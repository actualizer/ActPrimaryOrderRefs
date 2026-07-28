<?php declare(strict_types=1);

namespace Actualize\ActPrimaryOrderRefs\Subscriber;

use Actualize\ActPrimaryOrderRefs\Service\PrimaryOrderRefsFiller;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps the primary references current for orders that gain a transaction or a
 * delivery outside the cart-to-order conversion, so an import does not have to wait
 * for the next manual sweep before its documents and status columns resolve.
 */
class OrderRefsSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly PrimaryOrderRefsFiller $filler)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            OrderTransactionDefinition::ENTITY_NAME . '.written' => 'onOrderTransactionWritten',
            OrderDeliveryDefinition::ENTITY_NAME . '.written' => 'onOrderDeliveryWritten',
        ];
    }

    public function onOrderTransactionWritten(EntityWrittenEvent $event): void
    {
        $ids = $this->collectWrittenIds($event);

        if ($ids === []) {
            return;
        }

        $this->filler->fillTransactionsForTransactionIds($ids);
    }

    public function onOrderDeliveryWritten(EntityWrittenEvent $event): void
    {
        $ids = $this->collectWrittenIds($event);

        if ($ids === []) {
            return;
        }

        $this->filler->fillDeliveriesForDeliveryIds($ids);
    }

    /**
     * Deletes are skipped: removing a row can never supply a reference for an order
     * that is still missing one.
     *
     * @return list<string> binary(16) ids
     */
    private function collectWrittenIds(EntityWrittenEvent $event): array
    {
        $ids = [];

        foreach ($event->getWriteResults() as $writeResult) {
            if ($writeResult->getOperation() === EntityWriteResult::OPERATION_DELETE) {
                continue;
            }

            // Versioned entities report a composite primary key (`id` + `versionId`),
            // non-versioned ones a plain string.
            $primaryKey = $writeResult->getPrimaryKey();
            $id = \is_array($primaryKey) ? ($primaryKey['id'] ?? null) : $primaryKey;

            if (!\is_string($id) || !Uuid::isValid($id)) {
                continue;
            }

            $ids[] = Uuid::fromHexToBytes($id);
        }

        return array_values(array_unique($ids));
    }
}
