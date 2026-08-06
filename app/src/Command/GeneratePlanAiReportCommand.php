<?php

namespace App\Command;

use App\Exception\Ai\AiReportException;
use App\Entity\Plan;
use App\Repository\PlanRepository;
use App\Repository\ProjectRepository;
use App\Service\Ai\AiReportConfiguration;
use App\Service\Ai\AiReportStorage;
use App\Service\Ai\PlanAiReportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:ai:generate-plan-report',
    description: 'Genera o reutiliza el informe narrativo de IA de un proyecto.',
)]
final class GeneratePlanAiReportCommand extends Command
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly PlanRepository $planRepository,
        private readonly PlanAiReportService $planAiReportService,
        private readonly AiReportConfiguration $configuration,
        private readonly AiReportStorage $aiReportStorage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('projectId', InputArgument::REQUIRED, 'Identificador del proyecto')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Idioma del informe (es o en)', 'es');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectId = filter_var($input->getArgument('projectId'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($projectId === false) {
            $output->writeln('<error>El ID del proyecto debe ser un entero positivo.</error>');

            return Command::FAILURE;
        }

        $locale = (string) $input->getOption('locale');
        if (!in_array($locale, ['es', 'en'], true)) {
            $output->writeln('<error>El locale debe ser "es" o "en".</error>');

            return Command::FAILURE;
        }

        $project = $this->projectRepository->find($projectId);
        if ($project === null) {
            $output->writeln(sprintf('<error>No existe el proyecto con ID %d.</error>', $projectId));

            return Command::FAILURE;
        }

        $plan = $this->planRepository->findOneBy(['project' => $project]);
        if (!$plan instanceof Plan) {
            $output->writeln(sprintf('<error>El proyecto con ID %d no tiene un plan de Elaboración.</error>', $projectId));

            return Command::FAILURE;
        }

        $planId = $plan->getId();
        if ($planId === null) {
            $output->writeln('<error>El plan de Elaboración resuelto no está persistido.</error>');

            return Command::FAILURE;
        }

        try {
            $result = $this->planAiReportService->getOrGenerate($plan, $locale);
        } catch (AiReportException $exception) {
            $output->writeln(sprintf('<error>%s: %s</error>', $exception::class, $exception->getMessage()));

            return Command::FAILURE;
        } catch (\Throwable) {
            $output->writeln('<error>unexpected_ai_report_error: No se pudo generar el informe de IA.</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Proyecto ID: %d', $projectId));
        $output->writeln(sprintf('Plan ID: %d', $planId));
        $output->writeln(sprintf('Locale: %s', $locale));
        $output->writeln(sprintf('Proveedor: %s', trim($this->configuration->provider)));
        $output->writeln(sprintf('Modelo: %s', $this->configuration->model()));
        $output->writeln('Conclusión general: '.$result->generalConclusion);

        foreach ($result->categorySummaries as $summary) {
            $output->writeln(sprintf('Categoría %s: %s', $summary->categoryKey, $summary->summary));
        }

        $output->writeln(sprintf('Ruta JSON: %s', $this->aiReportStorage->pathFor($planId, $locale)));

        return Command::SUCCESS;
    }
}
