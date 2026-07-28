<?php declare(strict_types=1);

namespace Actualize\ActPrimaryOrderRefs\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Fills `order.primary_order_transaction_id` and `order.primary_order_delivery_id`
 * where they are still NULL.
 *
 * DBAL, not DAL: a DAL write would re-enter the write stack the subscriber is
 * called from, and its ReferenceVersionFieldSerializer would default
 * `primary_order_*_version_id` to the live version rather than the referenced
 * row's own version. The NULL guard is repeated in each UPDATE's WHERE clause so a
 * value appearing between the candidate SELECT and the UPDATE is not clobbered.
 */
class PrimaryOrderRefsFiller
{
    private const BATCH_SIZE = 1000;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function countOrdersMissingTransaction(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM `order`
             WHERE `order`.`primary_order_transaction_id` IS NULL
               AND EXISTS (
                   SELECT 1
                   FROM `order_transaction`
                   WHERE `order_transaction`.`order_id` = `order`.`id`
                     AND `order_transaction`.`order_version_id` = `order`.`version_id`
               )'
        );
    }

    public function countOrdersMissingDelivery(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM `order`
             WHERE `order`.`primary_order_delivery_id` IS NULL
               AND EXISTS (
                   SELECT 1
                   FROM `order_delivery`
                   WHERE `order_delivery`.`order_id` = `order`.`id`
                     AND `order_delivery`.`order_version_id` = `order`.`version_id`
               )'
        );
    }

    /**
     * Sweeps every order that has transactions but no primary transaction reference.
     *
     * @return int number of order rows updated
     */
    public function fillTransactions(): int
    {
        return $this->sweep(
            fn (): array => $this->fetchOrderIdsMissingTransaction(),
            fn (array $orderIds): int => $this->updateTransactions($orderIds)
        );
    }

    /**
     * Sweeps every order that has deliveries but no primary delivery reference.
     *
     * @return int number of order rows updated
     */
    public function fillDeliveries(): int
    {
        return $this->sweep(
            fn (): array => $this->fetchOrderIdsMissingDelivery(),
            fn (array $orderIds): int => $this->updateDeliveries($orderIds)
        );
    }

    /**
     * Fills the primary transaction reference for the orders owning the given transactions.
     *
     * @param list<string> $transactionIds binary(16) transaction ids
     *
     * @return int number of order rows updated
     */
    public function fillTransactionsForTransactionIds(array $transactionIds): int
    {
        if ($transactionIds === []) {
            return 0;
        }

        $orderIds = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT `order_transaction`.`order_id`
             FROM `order_transaction`
             INNER JOIN `order`
                 ON `order`.`id` = `order_transaction`.`order_id`
                 AND `order`.`version_id` = `order_transaction`.`order_version_id`
             WHERE `order_transaction`.`id` IN (:ids)
               AND `order`.`primary_order_transaction_id` IS NULL',
            ['ids' => $transactionIds],
            ['ids' => ArrayParameterType::BINARY]
        );

        if ($orderIds === []) {
            return 0;
        }

        return $this->updateTransactions($orderIds);
    }

    /**
     * Fills the primary delivery reference for the orders owning the given deliveries.
     *
     * @param list<string> $deliveryIds binary(16) delivery ids
     *
     * @return int number of order rows updated
     */
    public function fillDeliveriesForDeliveryIds(array $deliveryIds): int
    {
        if ($deliveryIds === []) {
            return 0;
        }

        $orderIds = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT `order_delivery`.`order_id`
             FROM `order_delivery`
             INNER JOIN `order`
                 ON `order`.`id` = `order_delivery`.`order_id`
                 AND `order`.`version_id` = `order_delivery`.`order_version_id`
             WHERE `order_delivery`.`id` IN (:ids)
               AND `order`.`primary_order_delivery_id` IS NULL',
            ['ids' => $deliveryIds],
            ['ids' => ArrayParameterType::BINARY]
        );

        if ($orderIds === []) {
            return 0;
        }

        return $this->updateDeliveries($orderIds);
    }

    /**
     * @param callable(): list<string> $fetchCandidates
     * @param callable(list<string>): int $update
     */
    private function sweep(callable $fetchCandidates, callable $update): int
    {
        $total = 0;

        do {
            $orderIds = $fetchCandidates();

            if ($orderIds === []) {
                break;
            }

            $updated = $update($orderIds);
            $total += $updated;

            // The candidate query only clears rows that the update actually filled.
            // Without this guard an update that matches nothing would re-select the
            // same batch forever.
            if ($updated === 0) {
                break;
            }
        } while (\count($orderIds) === self::BATCH_SIZE);

        return $total;
    }

    /**
     * @return list<string>
     */
    private function fetchOrderIdsMissingTransaction(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT `order`.`id`
             FROM `order`
             WHERE `order`.`primary_order_transaction_id` IS NULL
               AND EXISTS (
                   SELECT 1
                   FROM `order_transaction`
                   WHERE `order_transaction`.`order_id` = `order`.`id`
                     AND `order_transaction`.`order_version_id` = `order`.`version_id`
               )
             LIMIT :limit',
            ['limit' => self::BATCH_SIZE],
            ['limit' => ParameterType::INTEGER]
        );

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function fetchOrderIdsMissingDelivery(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT `order`.`id`
             FROM `order`
             WHERE `order`.`primary_order_delivery_id` IS NULL
               AND EXISTS (
                   SELECT 1
                   FROM `order_delivery`
                   WHERE `order_delivery`.`order_id` = `order`.`id`
                     AND `order_delivery`.`order_version_id` = `order`.`version_id`
               )
             LIMIT :limit',
            ['limit' => self::BATCH_SIZE],
            ['limit' => ParameterType::INTEGER]
        );

        return $ids;
    }

    /**
     * Picks the most recent transaction per order version.
     *
     * The `id ASC` tiebreaker keeps the choice deterministic when several
     * transactions share a `created_at` value, which is exactly what happens when an
     * import writes them in a single request.
     *
     * @param list<string> $orderIds binary(16) order ids
     */
    private function updateTransactions(array $orderIds): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE `order` AS `o`
             INNER JOIN (
                 SELECT `order_id`, `order_version_id`, `id`, `version_id`
                 FROM (
                     SELECT
                         `order_id`,
                         `order_version_id`,
                         `id`,
                         `version_id`,
                         ROW_NUMBER() OVER (
                             PARTITION BY `order_id`, `order_version_id`
                             ORDER BY `created_at` DESC, `id` ASC
                         ) AS `rn`
                     FROM `order_transaction`
                     WHERE `order_id` IN (:ids)
                 ) AS `ranked`
                 WHERE `ranked`.`rn` = 1
             ) AS `primary_transaction`
                 ON `primary_transaction`.`order_id` = `o`.`id`
                 AND `primary_transaction`.`order_version_id` = `o`.`version_id`
             SET `o`.`primary_order_transaction_id` = `primary_transaction`.`id`,
                 `o`.`primary_order_transaction_version_id` = `primary_transaction`.`version_id`
             WHERE `o`.`id` IN (:ids)
               AND `o`.`primary_order_transaction_id` IS NULL',
            ['ids' => $orderIds],
            ['ids' => ArrayParameterType::BINARY]
        );
    }

    /**
     * Picks the delivery carrying the highest shipping costs per order version.
     *
     * `shipping_costs` is JSON, so `unitPrice` has to be cast to a decimal before
     * comparing: as a string `9.90` would sort above `10.00`. The `id ASC`
     * tiebreaker keeps the choice deterministic for equal shipping costs.
     *
     * @param list<string> $orderIds binary(16) order ids
     */
    private function updateDeliveries(array $orderIds): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE `order` AS `o`
             INNER JOIN (
                 SELECT `order_id`, `order_version_id`, `id`, `version_id`
                 FROM (
                     SELECT
                         `order_id`,
                         `order_version_id`,
                         `id`,
                         `version_id`,
                         ROW_NUMBER() OVER (
                             PARTITION BY `order_id`, `order_version_id`
                             ORDER BY CAST(JSON_UNQUOTE(JSON_EXTRACT(`shipping_costs`, \'$.unitPrice\')) AS DECIMAL(20, 6)) DESC, `id` ASC
                         ) AS `rn`
                     FROM `order_delivery`
                     WHERE `order_id` IN (:ids)
                 ) AS `ranked`
                 WHERE `ranked`.`rn` = 1
             ) AS `primary_delivery`
                 ON `primary_delivery`.`order_id` = `o`.`id`
                 AND `primary_delivery`.`order_version_id` = `o`.`version_id`
             SET `o`.`primary_order_delivery_id` = `primary_delivery`.`id`,
                 `o`.`primary_order_delivery_version_id` = `primary_delivery`.`version_id`
             WHERE `o`.`id` IN (:ids)
               AND `o`.`primary_order_delivery_id` IS NULL',
            ['ids' => $orderIds],
            ['ids' => ArrayParameterType::BINARY]
        );
    }
}
