<?php

namespace App\Command;

use App\Service\MeasureTemplateV31Extractor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:extract-measure-template-v31',
    description: 'Extrae y valida la plantilla v31-2 de medidas'
)]
final class ExtractMeasureTemplateV31Command extends Command
{
    private const EXPECTED_MEASURES = 200;
    private const EXPECTED_POINTS = 564;
    private const EXPECTED_DEPARTMENTS = 22;

    public function __construct(
        private readonly MeasureTemplateV31Extractor $extractor,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Ruta al XLSX. Si es un filename, se busca en public/fixtures/')
            ->addOption('filename', null, InputOption::VALUE_REQUIRED, 'Nombre del fichero en public/fixtures/')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Ruta para guardar el JSON de salida');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $source = (string) ($input->getArgument('path') ?? '');
        $filename = (string) ($input->getOption('filename') ?? '');
        $resolvedPath = $this->resolveInputPath($source, $filename);

        if ($resolvedPath === null) {
            $io->error('Debes indicar una ruta válida o un filename existente en public/fixtures/.');
            return Command::FAILURE;
        }

        $data = $this->extractor->extractFile($resolvedPath);
        $summary = $data['summary'] ?? [];
        $warnings = $data['warnings'] ?? [];

        $io->title('Extractor plantilla medidas v31');
        $io->text(sprintf('Archivo: <info>%s</info>', $resolvedPath));
        $io->text(sprintf('Medidas: <info>%d</info>', (int) ($summary['measures_count'] ?? 0)));
        $io->text(sprintf('Puntos: <info>%d</info>', (int) ($summary['total_points'] ?? 0)));
        $io->text(sprintf('Departamentos: <info>%d</info>', count($summary['departments'] ?? [])));
        $io->newLine();

        if ($warnings !== []) {
            $io->warning(sprintf('Se han detectado %d avisos de parseo.', count($warnings)));
            foreach (array_slice($warnings, 0, 20) as $warning) {
                $io->writeln(sprintf(
                    '- fila %d [%s] %s',
                    (int) ($warning['row'] ?? 0),
                    (string) ($warning['code'] ?? 'warning'),
                    (string) ($warning['message'] ?? '')
                ));
            }

            if (count($warnings) > 20) {
                $io->writeln(sprintf('... y %d avisos más.', count($warnings) - 20));
            }
        }

        $issues = [];
        if ((int) ($summary['measures_count'] ?? 0) !== self::EXPECTED_MEASURES) {
            $issues[] = sprintf('Se esperaban %d medidas y se han extraído %d.', self::EXPECTED_MEASURES, (int) ($summary['measures_count'] ?? 0));
        }
        if ((int) ($summary['total_points'] ?? 0) !== self::EXPECTED_POINTS) {
            $issues[] = sprintf('Se esperaban %d puntos y se han extraído %d.', self::EXPECTED_POINTS, (int) ($summary['total_points'] ?? 0));
        }
        if (count($summary['departments'] ?? []) !== self::EXPECTED_DEPARTMENTS) {
            $issues[] = sprintf('Se esperaban %d departamentos y se han detectado %d.', self::EXPECTED_DEPARTMENTS, count($summary['departments'] ?? []));
        }

        if ($issues !== []) {
            $io->error($issues);
        } else {
            $io->success('Validación básica correcta.');
        }

        $outputPath = (string) ($input->getOption('output') ?? '');
        if ($outputPath !== '') {
            $this->writeJson($outputPath, $data);
            $io->success(sprintf('JSON guardado en %s', $outputPath));
        }

        return $issues === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function resolveInputPath(string $source, string $filename): ?string
    {
        $candidates = [];

        if ($source !== '') {
            $candidates[] = $source;
        }

        if ($filename !== '') {
            $candidates[] = $filename;
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return realpath($candidate) ?: $candidate;
            }

            if (!str_contains($candidate, DIRECTORY_SEPARATOR)) {
                $fixturePath = rtrim($this->projectDir, DIRECTORY_SEPARATOR) . '/public/fixtures/' . $candidate;
                if (is_file($fixturePath)) {
                    return realpath($fixturePath) ?: $fixturePath;
                }
            }
        }

        return null;
    }

    private function writeJson(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }
}
