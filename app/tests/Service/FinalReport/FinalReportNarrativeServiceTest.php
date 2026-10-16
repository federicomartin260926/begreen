<?php

declare(strict_types=1);

namespace App\Tests\Service\FinalReport;

use App\Entity\FinalReportContent;
use App\Entity\Project;
use App\Service\Ai\AiReportProviderInterface;
use App\Service\Ai\Dto\AiReportCategorySummary;
use App\Service\Ai\Dto\AiReportRequest;
use App\Service\Ai\Dto\AiReportResult;
use App\Service\FinalReport\FinalReportNarrativeService;
use PHPUnit\Framework\TestCase;

final class FinalReportNarrativeServiceTest extends TestCase
{
    public function testGeneratesOnlyOnExplicitCallAndProtectsManualText(): void
    {
        $provider = new class implements AiReportProviderInterface {
            public int $calls = 0;
            public ?AiReportRequest $request = null;

            public function generate(AiReportRequest $request): AiReportResult
            {
                ++$this->calls;
                $this->request = $request;

                return new AiReportResult(
                    'Resumen generado',
                    [new AiReportCategorySummary('implementation', 'Implementación generada')],
                    [],
                    'Conclusión generada',
                );
            }
        };
        $project = $this->project(10);
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setManualTexts([
                'executive_summary' => ['text' => 'Resumen manual', 'origin' => 'manual'],
                'implementation' => ['text' => 'Versión IA anterior', 'origin' => 'ai'],
            ]);
        $service = new FinalReportNarrativeService($provider);

        self::assertSame(0, $provider->calls);
        $updated = $service->generate($content, $this->report(10), 'es');

        self::assertSame(1, $provider->calls);
        self::assertSame('final_sustainability_report', $provider->request?->purpose);
        self::assertFalse($provider->request?->factualContext['emissions']['available']);
        self::assertSame([], $provider->request?->factualContext['associatedSdgsNotCertification']);
        self::assertArrayNotHasKey('trees', $provider->request?->factualContext ?? []);
        self::assertSame('Resumen manual', $content->getManualTexts()['executive_summary']['text']);
        self::assertSame('manual', $content->getManualTexts()['executive_summary']['origin']);
        self::assertSame(['text' => 'Implementación generada', 'origin' => 'ai'], $content->getManualTexts()['implementation']);
        self::assertContains('implementation', $updated);
        self::assertNotContains('executive_summary', $updated);
    }

    public function testManualReplacementMustBeExplicit(): void
    {
        $provider = $this->createMock(AiReportProviderInterface::class);
        $provider->expects(self::once())->method('generate')->willReturn(new AiReportResult(
            'Nuevo resumen',
            [new AiReportCategorySummary('implementation', 'Implementación')],
            [],
            'Conclusión',
        ));
        $project = $this->project(20);
        $content = (new FinalReportContent())->setProject($project)->setManualTexts([
            'executive_summary' => ['text' => 'Manual', 'origin' => 'manual'],
        ]);

        (new FinalReportNarrativeService($provider))->generate(
            $content,
            $this->report(20),
            'es',
            ['executive_summary'],
        );

        self::assertSame(['text' => 'Nuevo resumen', 'origin' => 'ai'], $content->getManualTexts()['executive_summary']);
    }

    public function testRejectsContentFromAnotherProjectBeforeCallingProvider(): void
    {
        $provider = $this->createMock(AiReportProviderInterface::class);
        $provider->expects(self::never())->method('generate');
        $content = (new FinalReportContent())->setProject($this->project(30));

        $this->expectException(\InvalidArgumentException::class);
        (new FinalReportNarrativeService($provider))->generate($content, $this->report(31), 'es');
    }

    private function project(int $id): Project
    {
        $project = (new Project())->setName('Proyecto final');
        (new \ReflectionProperty(Project::class, 'id'))->setValue($project, $id);

        return $project;
    }

    /** @return array<string, mixed> */
    private function report(int $projectId): array
    {
        return [
            'project' => ['id' => $projectId, 'name' => 'Proyecto final'],
            'plan' => ['status' => 'closed', 'protocol' => null, 'measures' => []],
            'implementation' => ['summary' => ['totalMeasures' => 0], 'measures' => []],
            'emissions' => ['available' => false, 'validRecordCount' => 0, 'totalKgCo2e' => null, 'byCategory' => [], 'byPhase' => []],
            'ods' => [],
            'commitment' => [],
        ];
    }

    public function testSkipsAiCallWhenAllGenerableSectionsAreManual(): void
    {
        $project = new \App\Entity\Project();
        (new \ReflectionProperty(\App\Entity\Project::class, 'id'))
            ->setValue($project, 72);

        $content = (new \App\Entity\FinalReportContent())
            ->setProject($project)
            ->setManualTexts([
                'executive_summary' => ['text' => 'Resumen manual', 'origin' => 'manual'],
                'implementation' => ['text' => 'Implementación manual', 'origin' => 'manual'],
                'conclusion' => ['text' => 'Conclusión manual', 'origin' => 'manual'],
            ]);

        $provider = $this->createMock(\App\Service\Ai\AiReportProviderInterface::class);
        $provider->expects(self::never())->method('generate');

        $service = new \App\Service\FinalReport\FinalReportNarrativeService($provider);

        $updated = $service->generate(
            $content,
            [
                'project' => ['id' => 72],
                'plan' => ['measures' => []],
                'implementation' => ['measures' => []],
            ],
            'es',
        );

        self::assertSame([], $updated);
        self::assertSame('Resumen manual', $content->getManualTexts()['executive_summary']['text']);
        self::assertSame('manual', $content->getManualTexts()['implementation']['origin']);
        self::assertSame('Conclusión manual', $content->getManualTexts()['conclusion']['text']);
    }
}
