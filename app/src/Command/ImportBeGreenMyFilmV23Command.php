<?php

namespace App\Command;

use App\Service\Import\BeGreenMyFilmV23Parser;
use App\Service\Import\BeGreenMyFilmV23Report;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:be-green-my-film-v23',
    description: 'Valida en dry-run la plantilla PLANTILLA_PS_v23.xlsx de Be Green My Film'
)]
final class ImportBeGreenMyFilmV23Command extends Command
{
    public function __construct(private readonly BeGreenMyFilmV23Parser $parser)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Ruta absoluta al XLSX')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ejecuta solo lectura y validación')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Importación real (no implementada todavía)')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Ruta para guardar el reporte JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('path');

        if (!is_file($path)) {
            $io->error(sprintf('No existe el archivo: %s', $path));
            return Command::FAILURE;
        }

        if ($input->getOption('apply')) {
            $io->error('La importación real todavía no está implementada. Usa el modo dry-run.');
            return Command::FAILURE;
        }

        $report = $this->parser->parseFile($path);
        $this->printReport($io, $report);

        $reportPath = $input->getOption('report');
        if (is_string($reportPath) && $reportPath !== '') {
            file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $io->success(sprintf('Reporte guardado en %s', $reportPath));
        }

        return match ($report->getStatus()) {
            'FAILED' => Command::FAILURE,
            default => Command::SUCCESS,
        };
    }

    private function printReport(SymfonyStyle $io, BeGreenMyFilmV23Report $report): void
    {
        $data = $report->jsonSerialize();

        $io->title('Be Green My Film v23 dry-run');
        $io->text(sprintf('Estado: <info>%s</info>', $report->getStatus()));
        $io->text(sprintf('Hoja: %s', $data['sheetName'] ?? ''));
        $io->text(sprintf('Dimensión: %s', $data['dimension'] ?? ''));
        $io->newLine();

        $io->section('Conteos');
        $io->writeln([
            sprintf('Medidas: %d', $report->getMeasureCount()),
            sprintf('Puntos: %d', $report->getTotalPoints()),
        ]);

        $io->section('Distribución');
        foreach ($report->getScoreDistribution() as $score => $count) {
            $io->writeln(sprintf('%d puntos: %d', $score, $count));
        }

        $io->section('Categorías');
        $io->writeln($this->renderNamedList($data['categories'] ?? []));

        $io->section('Bloques');
        $io->writeln($this->renderNamedList($data['blocks'] ?? []));

        $io->section('Departamentos');
        $io->writeln($this->renderNamedList($data['departments'] ?? []));

        $io->section('Fuentes de verificación');
        $io->writeln($this->renderNamedList($data['verificationSources'] ?? []));

        $io->section('ODS');
        $io->writeln($this->renderNamedList($data['ods'] ?? []));

        $io->section('Áreas de impacto');
        $io->writeln($this->renderNamedList($data['impactAreas'] ?? []));

        $io->section('Triple balance');
        $io->writeln($this->renderNamedList($data['tripleBalanceAxes'] ?? []));

        if ($report->getWarnings() !== []) {
            $io->warning('Warnings');
            foreach ($report->getWarnings() as $warning) {
                $io->writeln(sprintf('- [%s] %s', $warning['code'], $warning['message']));
            }
        }

        if ($report->getErrors() !== []) {
            $io->error('Errors');
            foreach ($report->getErrors() as $error) {
                $io->writeln(sprintf('- [%s] %s', $error['code'], $error['message']));
            }
        }
    }

    private function renderNamedList(array $items): array
    {
        if ($items === []) {
            return ['-'];
        }

        $lines = [];
        foreach ($items as $item) {
            $lines[] = sprintf('- %s (%s): %d', $item['name'] ?? '', $item['code'] ?? '', $item['count'] ?? 0);
        }

        return $lines;
    }
}
