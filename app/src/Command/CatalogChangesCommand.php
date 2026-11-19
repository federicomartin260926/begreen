<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:catalog:changes',
    description: 'READ-ONLY: detecta medidas modificadas tras el corte indicado.',
)]
final class CatalogChangesCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('since', null, InputOption::VALUE_REQUIRED, 'Corte exacto YYYY-MM-DD HH:MM:SS');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getOption('since');
        if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $raw)) {
            $output->writeln('ERROR=INVALID_SINCE');
            $output->writeln('CATALOG_CHANGE_GUARD=ERROR');
            return Command::INVALID;
        }

        $cutoff = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if ($cutoff === false || $cutoff->format('Y-m-d H:i:s') !== $raw
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            $output->writeln('ERROR=INVALID_SINCE');
            $output->writeln('CATALOG_CHANGE_GUARD=ERROR');
            return Command::INVALID;
        }

        try {
            // SELECT-only queries via DBAL; this command does not mutate catalog data.
            $summary = $this->connection->fetchAssociative(
                'SELECT COUNT(*) AS total, MIN(updated_at) AS first_updated, MAX(updated_at) AS last_updated FROM measure'
            );
            $changed = $this->connection->fetchAllAssociative(
                'SELECT catalog_id, name, updated_at FROM measure WHERE updated_at > :since ORDER BY updated_at ASC, catalog_id ASC',
                ['since' => $raw]
            );
        } catch (\Throwable) {
            // Avoid leaking connection parameters or credentials to deployment logs.
            $output->writeln('ERROR=CATALOG_READ_FAILED');
            $output->writeln('CATALOG_CHANGE_GUARD=ERROR');
            return Command::FAILURE;
        }

        $output->writeln('TOTAL_MEASURES=' . (int) ($summary['total'] ?? 0));
        $output->writeln('FIRST_UPDATED=' . (string) ($summary['first_updated'] ?? 'NULL'));
        $output->writeln('LAST_UPDATED=' . (string) ($summary['last_updated'] ?? 'NULL'));
        $output->writeln('SINCE=' . $raw);
        $output->writeln('CHANGED_MEASURES=' . count($changed));

        foreach ($changed as $measure) {
            // Prevent multiline/control-character injection into TXT deployment reports.
            $id = preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) ($measure['catalog_id'] ?? 'NULL'));
            $name = preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) ($measure['name'] ?? ''));
            $updatedAt = (string) ($measure['updated_at'] ?? 'NULL');
            $output->writeln('MEASURE=' . $id . ' | NAME=' . $name . ' | UPDATED_AT=' . $updatedAt);
        }

        if ($changed !== []) {
            $output->writeln('CATALOG_CHANGE_GUARD=FAIL');
            return Command::FAILURE;
        }

        $output->writeln('CATALOG_CHANGE_GUARD=PASS');
        return Command::SUCCESS;
    }
}
