<?php

namespace App\Command;

use App\Service\MeasureTemplateParser;
use App\Service\MeasureTemplateSchema;
use App\Service\MeasureTemplateV31Extractor;
use App\Service\MeasureTemplateV31FixtureBuilder;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:build-measure-fixture-from-v31',
    description: 'Convierte la plantilla v31-2 al formato interno y actualiza el fixture de medidas'
)]
final class BuildMeasureFixtureFromV31Command extends Command
{
    private const EXPECTED_MEASURES = 200;
    private const EXPECTED_POINTS = 564;
    private const EXPECTED_DEPARTMENTS = 22;

    public function __construct(
        private readonly MeasureTemplateV31Extractor $extractor,
        private readonly MeasureTemplateV31FixtureBuilder $builder,
        private readonly MeasureTemplateParser $parser,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Ruta al XLSX/JSON de origen. Si es un filename, se busca en public/fixtures/')
            ->addOption('input', null, InputOption::VALUE_REQUIRED, 'Ruta al XLSX/JSON de origen')
            ->addOption('filename', null, InputOption::VALUE_REQUIRED, 'Nombre del fichero en public/fixtures/')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Ruta para guardar el XLSX de salida');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $source = (string) ($input->getOption('input') ?: $input->getArgument('path') ?: '');
        $filename = (string) ($input->getOption('filename') ?? '');
        $resolvedPath = $this->resolveInputPath($source, $filename);

        if ($resolvedPath === null) {
            $io->error('Debes indicar una ruta válida, un JSON/XLSX existente o un filename existente en public/fixtures/.');

            return Command::FAILURE;
        }

        $data = $this->loadExtractionData($resolvedPath);
        $summary = $data['summary'] ?? [];
        $warnings = $data['warnings'] ?? [];

        $io->title('Transformador plantilla medidas v31');
        $io->text(sprintf('Origen: <info>%s</info>', $resolvedPath));
        $io->text(sprintf('Medidas extraídas: <info>%d</info>', (int) ($summary['measures_count'] ?? 0)));
        $io->text(sprintf('Puntos totales: <info>%d</info>', (int) ($summary['total_points'] ?? 0)));
        $io->text(sprintf('Departamentos detectados: <info>%d</info>', count($summary['departments'] ?? [])));
        $io->newLine();

        if ($warnings !== []) {
            $io->warning(sprintf('Extractor v31 ha reportado %d avisos.', count($warnings)));
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

        $issues = $this->validateSummary($summary);
        if ($issues !== []) {
            $io->warning($issues);
        }

        $spreadsheet = $this->builder->build($data);

        $outputPath = $this->resolveOutputPath((string) ($input->getOption('output') ?? ''));
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);

        $io->success(sprintf('XLSX generado en %s', $outputPath));

        $report = $this->parser->parseFile($outputPath);
        $parsedRows = $report->getRows();
        $parsedMeasures = count($parsedRows);
        $parsedPoints = array_sum(array_map(
            static fn (array $row): int => (int) ($row['score'] ?? 0),
            $parsedRows
        ));
        $parsedDepartments = $this->collectUniqueValues($parsedRows, 'departments');

        $validationIssues = [];
        if ($parsedMeasures !== self::EXPECTED_MEASURES) {
            $validationIssues[] = sprintf('Se esperaban %d medidas y el XLSX parseado contiene %d.', self::EXPECTED_MEASURES, $parsedMeasures);
        }
        if ($parsedPoints !== self::EXPECTED_POINTS) {
            $validationIssues[] = sprintf('Se esperaban %d puntos y el XLSX parseado contiene %d.', self::EXPECTED_POINTS, $parsedPoints);
        }
        if (count($parsedDepartments) !== self::EXPECTED_DEPARTMENTS) {
            $validationIssues[] = sprintf('Se esperaban %d departamentos y el XLSX parseado contiene %d.', self::EXPECTED_DEPARTMENTS, count($parsedDepartments));
        }

        if ($validationIssues !== []) {
            $io->error($validationIssues);

            return Command::FAILURE;
        }

        $io->success('Validación del XLSX generado correcta.');

        return Command::SUCCESS;
    }

    private function loadExtractionData(string $resolvedPath): array
    {
        if (strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)) === 'json') {
            $contents = file_get_contents($resolvedPath);
            if ($contents === false) {
                throw new \RuntimeException(sprintf('No se puede leer el fichero JSON "%s".', $resolvedPath));
            }

            return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        }

        return $this->extractor->extractFile($resolvedPath);
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

    private function resolveOutputPath(string $outputPath): string
    {
        if ($outputPath === '') {
            return rtrim($this->projectDir, DIRECTORY_SEPARATOR) . '/public/fixtures/be_green_my_film_measures.xlsx';
        }

        if (str_starts_with($outputPath, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $outputPath) === 1) {
            return $outputPath;
        }

        return rtrim($this->projectDir, DIRECTORY_SEPARATOR) . '/' . ltrim($outputPath, DIRECTORY_SEPARATOR);
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return string[]
     */
    private function validateSummary(array $summary): array
    {
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

        return $issues;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, string>
     */
    private function collectUniqueValues(array $rows, string $key): array
    {
        $set = [];
        foreach ($rows as $row) {
            $values = is_string($row[$key] ?? null)
                ? MeasureTemplateSchema::splitMultiValueCell((string) ($row[$key] ?? ''))
                : (array) ($row[$key] ?? []);

            foreach ($values as $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }

                $set[mb_strtolower($value)] = $value;
            }
        }

        return array_values($set);
    }
}
