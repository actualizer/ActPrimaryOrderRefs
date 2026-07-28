<?php declare(strict_types=1);

namespace Actualize\ActPrimaryOrderRefs\Command;

use Actualize\ActPrimaryOrderRefs\Service\PrimaryOrderRefsFiller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual sweep for orders whose primary references are still missing.
 *
 * The plugin migration only runs once per installed timestamp, so this command is
 * the way to re-check and repair a shop without cutting a new release.
 */
#[AsCommand(
    name: 'act:primary-order-refs:fill',
    description: 'Fills missing primary order transaction and delivery references'
)]
class FillPrimaryOrderRefsCommand extends Command
{
    public function __construct(private readonly PrimaryOrderRefsFiller $filler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Only report how many orders are missing a reference, write nothing'
        );
        $this->addOption(
            'transactions-only',
            null,
            InputOption::VALUE_NONE,
            'Process the primary transaction reference only'
        );
        $this->addOption(
            'deliveries-only',
            null,
            InputOption::VALUE_NONE,
            'Process the primary delivery reference only'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $transactionsOnly = (bool) $input->getOption('transactions-only');
        $deliveriesOnly = (bool) $input->getOption('deliveries-only');

        if ($transactionsOnly && $deliveriesOnly) {
            $io->error('--transactions-only and --deliveries-only are mutually exclusive.');

            return Command::INVALID;
        }

        $doTransactions = !$deliveriesOnly;
        $doDeliveries = !$transactionsOnly;

        $io->title('Primary order references');

        if ((bool) $input->getOption('dry-run')) {
            if ($doTransactions) {
                $io->writeln(sprintf(
                    'Orders missing a primary transaction: <info>%d</info>',
                    $this->filler->countOrdersMissingTransaction()
                ));
            }

            if ($doDeliveries) {
                $io->writeln(sprintf(
                    'Orders missing a primary delivery: <info>%d</info>',
                    $this->filler->countOrdersMissingDelivery()
                ));
            }

            $io->note('Dry run — nothing was written.');

            return Command::SUCCESS;
        }

        if ($doTransactions) {
            $io->writeln(sprintf(
                'Filled primary transaction on <info>%d</info> orders.',
                $this->filler->fillTransactions()
            ));
        }

        if ($doDeliveries) {
            $io->writeln(sprintf(
                'Filled primary delivery on <info>%d</info> orders.',
                $this->filler->fillDeliveries()
            ));
        }

        $io->success('Done.');

        return Command::SUCCESS;
    }
}
