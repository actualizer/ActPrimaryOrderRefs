<?php declare(strict_types=1);

namespace Actualize\ActPrimaryOrderRefs\Migration;

use Actualize\ActPrimaryOrderRefs\Service\PrimaryOrderRefsFiller;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * One-off backfill for orders that already exist when the plugin is installed.
 *
 * Runs as a migration rather than from install()/activate() so the work is batched
 * and does not block plugin activation.
 */
class Migration1785189600BackfillPrimaryOrderRefs extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785189600;
    }

    public function update(Connection $connection): void
    {
        $filler = new PrimaryOrderRefsFiller($connection);

        $filler->fillTransactions();
        $filler->fillDeliveries();
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes; the migration only fills NULL columns.
    }
}
