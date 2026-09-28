<?php

namespace App\Command;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Entity\Protocol;
use App\Enum\CommercialPhase;
use App\Repository\MeasureRepository;
use App\Repository\PlanRepository;
use App\Repository\ProjectRepository;
use App\Service\Animation\AnimationNotApplicableValidator;
use App\Service\CommercialPlanResolver;
use App\Service\ProtocolAvailabilityResolver;
use App\Service\SustainabilityPlanCompletionService;
use App\Service\SustainabilityPlanMeasureOrderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-sustainability-plan',
    description: 'Crea o reseedea un plan de sostenibilidad con medidas aleatorias del plan comercial asignado al proyecto',
)]
final class SeedSustainabilityPlanCommand extends Command
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly PlanRepository $planRepository,
        private readonly MeasureRepository $measureRepository,
        private readonly CommercialPlanResolver $commercialPlanResolver,
        private readonly ProtocolAvailabilityResolver $protocolAvailabilityResolver,
        private readonly AnimationNotApplicableValidator $animationNotApplicableValidator,
        private readonly SustainabilityPlanCompletionService $completionService,
        private readonly SustainabilityPlanMeasureOrderer $measureOrderer,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('projectId', InputArgument::REQUIRED, 'Identificador del proyecto')
            ->addArgument('measures', InputArgument::OPTIONAL, 'Número de medidas aleatorias a crear');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projectId = (int) $input->getArgument('projectId');
        $requestedMeasures = $this->normalizeRequestedMeasures($input->getArgument('measures'));

        if ($projectId <= 0) {
            $io->error('Debes indicar un identificador de proyecto válido.');

            return Command::FAILURE;
        }

        $project = $this->projectRepository->find($projectId);
        if (!$project instanceof Project) {
            $io->error(sprintf('No existe el proyecto con ID %d.', $projectId));

            return Command::FAILURE;
        }

        $subscription = $this->commercialPlanResolver->getSubscription($project, CommercialPhase::ELABORATION);
        if (!$subscription instanceof ProjectSubscription) {
            $io->error(sprintf('El proyecto %d no tiene un plan comercial asignado.', $projectId));

            return Command::FAILURE;
        }

        $commercialPlan = $this->commercialPlanResolver->getPlanForProject($project, CommercialPhase::ELABORATION);

        $plan = $this->planRepository->findOneBy(['project' => $project]);
        $existingPlan = $plan instanceof Plan;

        if (!$existingPlan) {
            $plan = (new Plan())
                ->setProject($project)
                ->setUser($this->resolvePlanUser($project));
            $this->entityManager->persist($plan);
        }

        $protocol = $plan->getProtocol();

        if ($protocol instanceof Protocol
            && !$this->protocolAvailabilityResolver->isAvailable($project, $protocol)) {
            $io->error(sprintf(
                'El protocolo actual "%s" no es compatible con la configuración del proyecto.',
                (string) $protocol->getName(),
            ));

            return Command::FAILURE;
        }

        if (!$protocol instanceof Protocol) {
            $availableProtocols = $this->protocolAvailabilityResolver->getAvailableProtocols($project);

            if (count($availableProtocols) !== 1) {
                $io->error(sprintf(
                    'Se esperaba exactamente un protocolo disponible para el proyecto y se encontraron %d.',
                    count($availableProtocols),
                ));

                return Command::FAILURE;
            }

            $protocol = $availableProtocols[0];
        }

        if ($existingPlan) {
            $this->resetPlan($plan);
        }

        $plan->setProtocol($protocol);
        $plan->setUser($this->resolvePlanUser($project));
        $plan->setCustomMeasures(null);

        $catalogMeasures = $this->measureRepository->getCatalogMeasuresForProtocol($project, $protocol);
        $availableMeasures = array_values(array_filter(
            $catalogMeasures,
            static fn (Measure $measure): bool => $measure->getId() !== null
        ));
        $availableMeasures = $this->measureOrderer->sortVisibleMeasures($availableMeasures, $protocol->getGroupingBy());

        $availableCount = count($availableMeasures);
        if ($availableCount === 0) {
            $io->error('No hay medidas disponibles para el plan comercial del proyecto.');

            return Command::FAILURE;
        }

        $targetCount = $requestedMeasures ?? $availableCount;
        if ($targetCount > $availableCount) {
            $targetCount = $availableCount;
        }

        $selectedMeasures = $this->selectRandomMeasures($availableMeasures, $targetCount, $protocol);

        $seedSummary = [
            'notApplicable' => 0,
            'applicableWillImplement' => 0,
            'applicableWillNotImplement' => 0,
            'critical' => 0,
            'nonCritical' => 0,
        ];

        foreach ($selectedMeasures as $measure) {
            $planMeasure = new PlanMeasure();
            $plan->addPlanMeasure($planMeasure);
            $planMeasure->setMeasure($measure);
            $planMeasure->markAsManual();
            $this->populateSeedPlanMeasureState($planMeasure, $project, $seedSummary);
            $this->entityManager->persist($planMeasure);
        }

        $planComplete = $this->completionService->syncStatus($plan, $project, $this->measureRepository);
        $this->entityManager->flush();

        $io->title('Plan de sostenibilidad generado');
        $io->text(sprintf('Proyecto: <info>%s</info> (#%d)', (string) $project->getName(), (int) $project->getId()));
        $io->text(sprintf('Plan comercial: <info>%s</info> (%s)', $commercialPlan->getName(), $commercialPlan->getCode()));
        $io->text(sprintf('Protocolo: <info>%s</info>', (string) $protocol->getName()));
        $io->text(sprintf('Medidas disponibles para este plan: <info>%d</info>', $availableCount));
        $io->text(sprintf('Medidas creadas: <info>%d</info>', count($selectedMeasures)));
        $io->text(sprintf('Estado del plan: <info>%s</info>', $plan->getStatus()));
        $io->section('Resumen aleatorio');
        $io->listing([
            sprintf('No aplicables: %d', $seedSummary['notApplicable']),
            sprintf('Aplicables a implementar: %d', $seedSummary['applicableWillImplement']),
            sprintf('Aplicables no implementadas: %d', $seedSummary['applicableWillNotImplement']),
            sprintf('Críticas: %d', $seedSummary['critical']),
            sprintf('No críticas: %d', $seedSummary['nonCritical']),
        ]);

        if ($requestedMeasures !== null && $requestedMeasures > $availableCount) {
            $io->warning(sprintf(
                'Se pidieron %d medidas, pero el plan comercial sólo permite %d. Se ha aplicado el límite.',
                $requestedMeasures,
                $availableCount
            ));
        }

        $io->success($planComplete ? 'El plan ha quedado completo.' : 'El plan ha quedado incompleto con las medidas seleccionadas.');

        return Command::SUCCESS;
    }

    private function normalizeRequestedMeasures(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $count = (int) $value;

        return $count > 0 ? $count : null;
    }

    private function resolvePlanUser(Project $project): ?\App\Entity\User
    {
        $user = $project->getUser();
        if ($user instanceof \App\Entity\User) {
            return $user;
        }

        throw new \RuntimeException(sprintf('El proyecto %d no tiene usuario asociado y no se puede crear el plan.', (int) $project->getId()));
    }

    private function resetPlan(Plan $plan): void
    {
        foreach (clone $plan->getPlanMeasures() as $planMeasure) {
            if ($planMeasure instanceof PlanMeasure) {
                $plan->removePlanMeasure($planMeasure);
            }
        }

        foreach (clone $plan->getBlockAnswers() as $blockAnswer) {
            $plan->removeBlockAnswer($blockAnswer);
        }

        $plan->setCustomMeasures(null);
        $plan->setStatus('incompleto');
        $plan->setStatusChangedAt(new \DateTimeImmutable());
    }

    /**
     * @param list<Measure> $availableMeasures
     *
     * @return list<Measure>
     */
    private function selectRandomMeasures(array $availableMeasures, int $targetCount, Protocol $protocol): array
    {
        $selectedMeasures = $availableMeasures;

        if ($targetCount < count($selectedMeasures)) {
            shuffle($selectedMeasures);
            $selectedMeasures = array_slice($selectedMeasures, 0, $targetCount);
        }

        return $this->measureOrderer->sortVisibleMeasures($selectedMeasures, $protocol->getGroupingBy());
    }

    /**
     * @param array{
     *     notApplicable: int,
     *     applicableWillImplement: int,
     *     applicableWillNotImplement: int,
     *     critical: int,
     *     nonCritical: int
     * } $seedSummary
     */
    private function populateSeedPlanMeasureState(
        PlanMeasure $planMeasure,
        Project $project,
        array &$seedSummary,
    ): void {
        $measure = $planMeasure->getMeasure();
        if (!$measure instanceof Measure) {
            throw new \LogicException('No se puede generar estado para un PlanMeasure sin Measure.');
        }

        $isApplicable = $this->randomBoolean();
        if (!$isApplicable && !$this->animationNotApplicableValidator->allows($project, $measure)) {
            $isApplicable = true;
        }

        $planMeasure->setIsApplicable($isApplicable);

        if (!$isApplicable) {
            ++$seedSummary['notApplicable'];

            $planMeasure->setWillImplement(null);
            $planMeasure->setIsCritical(null);
            $planMeasure->setImplemented(null);
            $planMeasure->setObservations($this->randomObservation());

            return;
        }

        $willImplement = $this->randomBoolean();
        $planMeasure->setWillImplement($willImplement);
        $planMeasure->setImplemented(null);

        if (!$willImplement) {
            ++$seedSummary['applicableWillNotImplement'];

            $planMeasure->setIsCritical(null);
            $planMeasure->setObservations($this->randomObservation());

            return;
        }

        ++$seedSummary['applicableWillImplement'];

        $isCritical = $this->randomBoolean();
        $planMeasure->setIsCritical($isCritical);

        if ($isCritical) {
            ++$seedSummary['critical'];
        } else {
            ++$seedSummary['nonCritical'];
        }

        $planMeasure->setObservations($this->randomObservation());
    }

    private function randomBoolean(): bool
    {
        return random_int(0, 1) === 1;
    }

    private function randomObservation(): string
    {
        $observations = [
            'Medida prioritaria por impacto operativo y seguimiento durante la ejecución.',
            'Requiere coordinación con el equipo de producción antes de su implementación.',
            'Decisión registrada para facilitar el seguimiento y la revisión posterior del plan.',
            'Necesita validación adicional del equipo responsable antes de iniciar la implementación.',
        ];

        return $observations[random_int(0, count($observations) - 1)];
    }
}
