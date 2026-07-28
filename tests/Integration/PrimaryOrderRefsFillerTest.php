<?php declare(strict_types=1);

namespace Actualize\ActPrimaryOrderRefs\Tests\Integration;

use Actualize\ActPrimaryOrderRefs\Service\PrimaryOrderRefsFiller;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Loader\InitialStateIdLoader;
use Shopware\Core\Test\Integration\Traits\OrderFixture;

/**
 * The value of this plugin is the correctness of four SQL statements against the real
 * order schema, so these run against the container and the DAL rather than a mocked
 * Connection — a unit test here would only restate the SQL string back to itself.
 */
class PrimaryOrderRefsFillerTest extends TestCase
{
    use IntegrationTestBehaviour;
    use KernelTestBehaviour;
    use OrderFixture;

    public function testPicksMostRecentTransactionAndBreaksTiesByIdAscending(): void
    {
        $context = Context::createDefaultContext();
        $orderId = Uuid::randomHex();
        $seedTransactionId = Uuid::randomHex();

        $this->createOrder($orderId, $seedTransactionId, $context);
        // The fixture writes the seed transaction with `created_at = now`, which would
        // otherwise outrank every timestamp set below.
        $this->setTransactionCreatedAt($seedTransactionId, '2025-01-01 10:00:00.000');

        // Two transactions share the newest timestamp — exactly what an import writing
        // several rows in one request produces. The tiebreaker has to make the choice
        // deterministic, and it picks the lower id.
        [$lowerId, $higherId] = $this->sortedUuidPair();

        $this->cloneTransaction($seedTransactionId, $higherId, '2026-01-02 10:00:00.000');
        $this->cloneTransaction($seedTransactionId, $lowerId, '2026-01-02 10:00:00.000');
        // An older transaction that must lose.
        $this->cloneTransaction($seedTransactionId, Uuid::randomHex(), '2026-01-01 10:00:00.000');

        $this->clearPrimaryRefs($orderId);

        self::assertSame(1, $this->filler()->fillTransactions());
        self::assertSame($lowerId, $this->readPrimaryTransactionId($orderId));
    }

    public function testPicksDeliveryWithHighestShippingCostsNumerically(): void
    {
        $context = Context::createDefaultContext();
        $orderId = Uuid::randomHex();

        $seedDeliveryId = $this->createOrder($orderId, Uuid::randomHex(), $context);

        // 9.90 sorts above 10.00 as a string, so a missing numeric cast would pick the
        // cheaper delivery here.
        $cheaperId = Uuid::randomHex();
        $expensiveId = Uuid::randomHex();
        $this->cloneDelivery($seedDeliveryId, $cheaperId, 9.90);
        $this->cloneDelivery($seedDeliveryId, $expensiveId, 10.00);
        $this->setDeliveryShippingCosts($seedDeliveryId, 1.00);

        $this->clearPrimaryRefs($orderId);

        self::assertSame(1, $this->filler()->fillDeliveries());
        self::assertSame($expensiveId, $this->readPrimaryDeliveryId($orderId));
    }

    public function testNeverOverwritesAnExistingReference(): void
    {
        $context = Context::createDefaultContext();
        $orderId = Uuid::randomHex();
        $seedTransactionId = Uuid::randomHex();

        $this->createOrder($orderId, $seedTransactionId, $context);

        // A newer transaction exists, but the order already points somewhere. The
        // reference is data an operator or another plugin may have set on purpose.
        $this->cloneTransaction($seedTransactionId, Uuid::randomHex(), '2030-01-01 10:00:00.000');
        $this->setPrimaryTransactionId($orderId, $seedTransactionId);

        self::assertSame(0, $this->filler()->fillTransactions());
        self::assertSame($seedTransactionId, $this->readPrimaryTransactionId($orderId));
    }

    public function testSubscriberFillsTheReferenceWhenATransactionIsWritten(): void
    {
        $context = Context::createDefaultContext();
        $orderId = Uuid::randomHex();

        $this->createOrder($orderId, Uuid::randomHex(), $context);
        $this->clearPrimaryRefs($orderId);
        self::assertNull($this->readPrimaryTransactionId($orderId));

        // Writing through the DAL is what an import does; the subscriber has to close
        // the gap without anyone running the command.
        $newTransactionId = Uuid::randomHex();
        $transactionRepository = $this->getContainer()->get('order_transaction.repository');
        \assert($transactionRepository instanceof EntityRepository);
        $transactionRepository->create([
            [
                'id' => $newTransactionId,
                'orderId' => $orderId,
                'paymentMethodId' => $this->readOrderPaymentMethodId($orderId),
                'amount' => new CalculatedPrice(10, 10, new CalculatedTaxCollection(), new TaxRuleCollection()),
                'stateId' => $this->initialTransactionStateId(),
            ],
        ], $context);

        self::assertSame($newTransactionId, $this->readPrimaryTransactionId($orderId));
    }

    public function testSubscriberFillsBothReferencesWhenOneWriteCreatesOrderAndAggregates(): void
    {
        $context = Context::createDefaultContext();
        $orderId = Uuid::randomHex();
        $transactionId = Uuid::randomHex();

        // The payload carries no primaryOrder* keys and creates the order together with
        // its transaction and delivery — what an API import sends. The order row has to
        // be visible to the subscriber by the time the aggregates report their write.
        $deliveryId = $this->createOrder($orderId, $transactionId, $context);

        self::assertSame($transactionId, $this->readPrimaryTransactionId($orderId));
        self::assertSame($deliveryId, $this->readPrimaryDeliveryId($orderId));
    }

    /**
     * Creates an order carrying one transaction and one delivery.
     *
     * @return string hex id of the delivery created by the fixture
     */
    private function createOrder(string $orderId, string $transactionId, Context $context): string
    {
        $orderRepository = $this->getContainer()->get('order.repository');
        \assert($orderRepository instanceof EntityRepository);

        $orderData = $this->getOrderData($orderId, $context);
        $orderData[0]['transactions'] = [
            [
                'id' => $transactionId,
                'paymentMethodId' => $orderData[0]['paymentMethodId'],
                'amount' => new CalculatedPrice(10, 10, new CalculatedTaxCollection(), new TaxRuleCollection()),
                'stateId' => $this->initialTransactionStateId(),
            ],
        ];

        $orderRepository->create($orderData, $context);

        $deliveryId = $orderData[0]['deliveries'][0]['id'];
        \assert(\is_string($deliveryId));

        return $deliveryId;
    }

    private function filler(): PrimaryOrderRefsFiller
    {
        $filler = $this->getContainer()->get(PrimaryOrderRefsFiller::class);
        \assert($filler instanceof PrimaryOrderRefsFiller);

        return $filler;
    }

    private function connection(): Connection
    {
        $connection = $this->getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }

    private function initialTransactionStateId(): string
    {
        $loader = $this->getContainer()->get(InitialStateIdLoader::class);
        \assert($loader instanceof InitialStateIdLoader);

        return $loader->get(OrderTransactionStates::STATE_MACHINE);
    }

    /**
     * @return array{0: string, 1: string} two hex uuids, lower one first
     */
    private function sortedUuidPair(): array
    {
        $a = Uuid::randomHex();
        $b = Uuid::randomHex();

        return $a < $b ? [$a, $b] : [$b, $a];
    }

    private function cloneTransaction(string $sourceId, string $newId, string $createdAt): void
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT * FROM `order_transaction` WHERE `id` = :id',
            ['id' => Uuid::fromHexToBytes($sourceId)]
        );
        \assert(\is_array($row));

        $row['id'] = Uuid::fromHexToBytes($newId);
        $row['created_at'] = $createdAt;

        $this->connection()->insert('order_transaction', $row);
    }

    private function setTransactionCreatedAt(string $transactionId, string $createdAt): void
    {
        $this->connection()->executeStatement(
            'UPDATE `order_transaction` SET `created_at` = :createdAt WHERE `id` = :id',
            [
                'createdAt' => $createdAt,
                'id' => Uuid::fromHexToBytes($transactionId),
            ]
        );
    }

    private function cloneDelivery(string $sourceId, string $newId, float $shippingCosts): void
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT * FROM `order_delivery` WHERE `id` = :id',
            ['id' => Uuid::fromHexToBytes($sourceId)]
        );
        \assert(\is_array($row));

        $row['id'] = Uuid::fromHexToBytes($newId);
        $row['shipping_costs'] = $this->shippingCostsJson($shippingCosts);

        $this->connection()->insert('order_delivery', $row);
    }

    private function setDeliveryShippingCosts(string $deliveryId, float $shippingCosts): void
    {
        $this->connection()->executeStatement(
            'UPDATE `order_delivery` SET `shipping_costs` = :costs WHERE `id` = :id',
            [
                'costs' => $this->shippingCostsJson($shippingCosts),
                'id' => Uuid::fromHexToBytes($deliveryId),
            ]
        );
    }

    private function shippingCostsJson(float $unitPrice): string
    {
        return json_encode([
            'unitPrice' => $unitPrice,
            'quantity' => 1,
            'totalPrice' => $unitPrice,
            'calculatedTaxes' => [],
            'taxRules' => [],
            'referencePrice' => null,
            'listPrice' => null,
            'regulationPrice' => null,
        ], \JSON_THROW_ON_ERROR);
    }

    private function clearPrimaryRefs(string $orderId): void
    {
        $this->connection()->executeStatement(
            'UPDATE `order`
             SET `primary_order_transaction_id` = NULL,
                 `primary_order_transaction_version_id` = NULL,
                 `primary_order_delivery_id` = NULL,
                 `primary_order_delivery_version_id` = NULL
             WHERE `id` = :id',
            ['id' => Uuid::fromHexToBytes($orderId)]
        );
    }

    private function setPrimaryTransactionId(string $orderId, string $transactionId): void
    {
        $this->connection()->executeStatement(
            'UPDATE `order` AS `o`
             INNER JOIN `order_transaction` AS `t` ON `t`.`id` = :transactionId
             SET `o`.`primary_order_transaction_id` = `t`.`id`,
                 `o`.`primary_order_transaction_version_id` = `t`.`version_id`
             WHERE `o`.`id` = :orderId',
            [
                'transactionId' => Uuid::fromHexToBytes($transactionId),
                'orderId' => Uuid::fromHexToBytes($orderId),
            ]
        );
    }

    private function readPrimaryTransactionId(string $orderId): ?string
    {
        return $this->readBinaryColumn('primary_order_transaction_id', $orderId);
    }

    private function readPrimaryDeliveryId(string $orderId): ?string
    {
        return $this->readBinaryColumn('primary_order_delivery_id', $orderId);
    }

    private function readBinaryColumn(string $column, string $orderId): ?string
    {
        $value = $this->connection()->fetchOne(
            \sprintf('SELECT LOWER(HEX(`%s`)) FROM `order` WHERE `id` = :id', $column),
            ['id' => Uuid::fromHexToBytes($orderId)]
        );

        return \is_string($value) ? $value : null;
    }

    private function readOrderPaymentMethodId(string $orderId): string
    {
        $value = $this->connection()->fetchOne(
            'SELECT LOWER(HEX(`payment_method_id`))
             FROM `order_transaction`
             WHERE `order_id` = :id
             LIMIT 1',
            ['id' => Uuid::fromHexToBytes($orderId)]
        );
        \assert(\is_string($value));

        return $value;
    }
}
