<?php

namespace App\Command;

use App\Exception\Ai\AiReportException;
use App\Service\Ai\AiReportConfiguration;
use App\Service\Ai\AiReportMeasureDecision;
use App\Service\Ai\AiReportProviderInterface;
use App\Service\Ai\Dto\AiReportCategory;
use App\Service\Ai\Dto\AiReportMeasure;
use App\Service\Ai\Dto\AiReportRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:ai:test-report',
    description: 'Genera un informe ficticio para comprobar la integración AI.',
)]
final class TestAiReportCommand extends Command
{
    public function __construct(
        private readonly AiReportProviderInterface $provider,
        private readonly AiReportConfiguration $configuration,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->provider->generate(new AiReportRequest(
                'es',
                [new AiReportCategory(
                    'category:1',
                    'Energía',
                    [
                        new AiReportMeasure(
                            'measure:1',
                            'Iluminación eficiente',
                            'Sustitución gradual de luminarias por alternativas eficientes.',
                            AiReportMeasureDecision::PLANNED,
                            true,
                            'Medida prevista para reducir el consumo eléctrico.',
                            4,
                        ),
                        new AiReportMeasure(
                            'measure:2',
                            'Control de consumo',
                            'Seguimiento periódico del consumo energético.',
                            AiReportMeasureDecision::NOT_APPLICABLE,
                            false,
                            'No se aplica en esta prueba ficticia.',
                            5,
                        ),
                    ],
                )],
            ));
        } catch (AiReportException $exception) {
            $output->writeln(sprintf('%s: %s', $exception::class, $exception->getMessage()));

            return Command::FAILURE;
        } catch (\Throwable $exception) {
            $output->writeln('unexpected_provider_error: Unexpected AI report provider error.');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Proveedor/modelo: %s / %s',
            $this->configuration->provider,
            $this->configuration->model(),
        ));
        $output->writeln('Conclusión general: '.$result->generalConclusion);

        foreach ($result->categorySummaries as $summary) {
            $output->writeln('Categoría: '.$summary->categoryKey);
            $output->writeln('Resumen: '.$summary->summary);
        }

        return Command::SUCCESS;
    }
}
