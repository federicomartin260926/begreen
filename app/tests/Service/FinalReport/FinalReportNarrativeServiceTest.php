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
use App\Exception\Ai\AiTimeoutException;
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

    public function testFeaturedMeasureAndEditorialTextAreIncludedInStorytellingContext(): void
    {
        $provider = new class implements AiReportProviderInterface {
            public ?AiReportRequest $request = null;

            public function generate(AiReportRequest $request): AiReportResult
            {
                $this->request = $request;

                return new AiReportResult(
                    'Resumen',
                    [
                        new AiReportCategorySummary('implementation', 'Implementación'),
                        new AiReportCategorySummary('featured_storytelling', 'Relato destacado'),
                    ],
                    [],
                    'Conclusión',
                );
            }
        };
        $project = $this->project(22);
        $content = (new FinalReportContent())
            ->setProject($project)
            ->setFeaturedMeasures([[
                'planMeasureId' => 70,
                'text' => 'Comentario editorial persistido',
            ]]);
        $report = $this->report(22);
        $report['plan']['measures'] = [[
            'planMeasureId' => 70,
            'measure' => ['name' => 'Movilidad sostenible'],
        ]];

        $updated = (new FinalReportNarrativeService($provider))->generate($content, $report, 'es');

        self::assertSame([[
            'measure' => 'Movilidad sostenible',
            'editorialText' => 'Comentario editorial persistido',
        ]], $provider->request?->factualContext['featuredMeasures']);
        self::assertContains('featured_storytelling', $provider->request?->sectionKeys ?? []);
        self::assertContains('featured_storytelling', $updated);
    }

    public function testFormatsOnlyEmissionValuesForTheRequestedLocale(): void
    {
        $provider = new class implements AiReportProviderInterface {
            /** @var list<AiReportRequest> */
            public array $requests = [];

            public function generate(AiReportRequest $request): AiReportResult
            {
                $this->requests[] = $request;

                return new AiReportResult(
                    'Resumen',
                    [new AiReportCategorySummary('implementation', 'Implementación')],
                    [],
                    'Conclusión',
                );
            }
        };
        $report = $this->report(25);
        $report['implementation']['summary']['totalMeasures'] = 7;
        $report['emissions'] = [
            'available' => true,
            'validRecordCount' => 3,
            'totalKgCo2e' => 2696.0169437365853,
            'byCategory' => [['name' => 'Energía', 'kgCo2e' => 415.88765399999994]],
            'byPhase' => [['code' => 'actividad', 'kgCo2e' => 1328.9682168000002]],
        ];
        $service = new FinalReportNarrativeService($provider);

        $service->generate((new FinalReportContent())->setProject($this->project(25)), $report, 'es');
        $service->generate((new FinalReportContent())->setProject($this->project(25)), $report, 'en_GB');

        $spanish = $provider->requests[0]->factualContext;
        self::assertSame('2.696,02', $spanish['emissions']['totalKgCo2e']);
        self::assertSame('415,89', $spanish['emissions']['byCategory'][0]['kgCo2e']);
        self::assertSame('1.328,97', $spanish['emissions']['byPhase'][0]['kgCo2e']);
        self::assertSame(3, $spanish['emissions']['validRecordCount']);
        self::assertSame(7, $spanish['implementation']['summary']['totalMeasures']);

        $english = $provider->requests[1]->factualContext;
        self::assertSame('2,696.02', $english['emissions']['totalKgCo2e']);
        self::assertSame('415.89', $english['emissions']['byCategory'][0]['kgCo2e']);
        self::assertSame('1,328.97', $english['emissions']['byPhase'][0]['kgCo2e']);
    }

    public function testRejectsContentFromAnotherProjectBeforeCallingProvider(): void
    {
        $provider = $this->createMock(AiReportProviderInterface::class);
        $provider->expects(self::never())->method('generate');
        $content = (new FinalReportContent())->setProject($this->project(30));

        $this->expectException(\InvalidArgumentException::class);
        (new FinalReportNarrativeService($provider))->generate($content, $this->report(31), 'es');
    }

    public function testGeneratesOnlyOneFeaturedMeasureWithWhitelistedFactualContext(): void
    {
        $provider = new class implements AiReportProviderInterface {
            public ?AiReportRequest $request = null;

            public function generate(AiReportRequest $request): AiReportResult
            {
                $this->request = $request;

                return new AiReportResult(
                    'Unused',
                    [new AiReportCategorySummary('featured_measure', ' Relato factual generado. ')],
                    [],
                    'Unused',
                );
            }
        };
        $content = (new FinalReportContent())
            ->setProject($this->project(40))
            ->setFeaturedMeasures([
                ['planMeasureId' => 70, 'text' => null, 'origin' => 'manual', 'photoDocumentId' => 5],
                ['planMeasureId' => 71, 'text' => 'No cambiar', 'origin' => 'manual', 'photoDocumentId' => null],
            ])
            ->setManualTexts(['conclusion' => ['text' => 'Conclusión manual', 'origin' => 'manual']]);
        $report = $this->featuredReport(40, 70, 'implemented');
        $report['emissions'] = ['totalKgCo2e' => 999];
        $report['ods'] = [['code' => '13']];
        $report['documents'] = [['name' => 'private.pdf']];

        $generated = (new FinalReportNarrativeService($provider))->generateFeaturedMeasure(
            $content,
            $report,
            70,
            'es',
        );

        self::assertSame([
            'planMeasureId' => 70,
            'text' => 'Relato factual generado.',
            'origin' => 'ai',
        ], $generated);
        self::assertSame(['featured_measure'], $provider->request?->sectionKeys);
        self::assertSame('es', $provider->request?->locale);
        self::assertSame('featured_measure', $provider->request?->factualContext['scope']);
        self::assertSame('implemented', $provider->request?->factualContext['measure']['implementation']['state']);
        self::assertSame(['name', 'type', 'country', 'mainLocation'], array_keys($provider->request?->factualContext['project'] ?? []));
        self::assertArrayNotHasKey('emissions', $provider->request?->factualContext ?? []);
        self::assertArrayNotHasKey('ods', $provider->request?->factualContext ?? []);
        self::assertArrayNotHasKey('documents', $provider->request?->factualContext ?? []);
        self::assertArrayNotHasKey('responsibleCrewMembers', $provider->request?->factualContext['measure']['implementation'] ?? []);
        self::assertSame('No cambiar', $content->getFeaturedMeasures()[1]['text']);
        self::assertSame(5, $content->getFeaturedMeasures()[0]['photoDocumentId']);
        self::assertSame('Conclusión manual', $content->getManualTexts()['conclusion']['text']);
    }

    public function testFeaturedMeasureContextPreservesRealStateAndRequestedLanguage(): void
    {
        $provider = new class implements AiReportProviderInterface {
            /** @var list<AiReportRequest> */
            public array $requests = [];

            public function generate(AiReportRequest $request): AiReportResult
            {
                $this->requests[] = $request;

                return new AiReportResult('Unused', [
                    new AiReportCategorySummary('featured_measure', 'Generated narrative'),
                ], [], 'Unused');
            }
        };
        $service = new FinalReportNarrativeService($provider);

        foreach (['pending', 'in_progress', 'implemented'] as $index => $state) {
            $id = 80 + $index;
            $service->generateFeaturedMeasure(
                (new FinalReportContent())->setProject($this->project(41))->setFeaturedMeasures([[
                    'planMeasureId' => $id,
                    'text' => null,
                    'origin' => 'manual',
                ]]),
                $this->featuredReport(41, $id, $state),
                $id,
                'en',
            );
        }

        self::assertSame(['pending', 'in_progress', 'implemented'], array_map(
            static fn (AiReportRequest $request): mixed => $request->factualContext['measure']['implementation']['state'],
            $provider->requests,
        ));
        self::assertSame(['en', 'en', 'en'], array_map(
            static fn (AiReportRequest $request): string => $request->locale,
            $provider->requests,
        ));
    }

    public function testManualFeaturedTextRequiresExplicitReplacementAndLegacyOriginIsManual(): void
    {
        $provider = $this->createMock(AiReportProviderInterface::class);
        $provider->expects(self::never())->method('generate');
        $content = (new FinalReportContent())->setProject($this->project(42))->setFeaturedMeasures([[
            'planMeasureId' => 90,
            'text' => 'Texto antiguo sin origen',
        ]]);

        try {
            (new FinalReportNarrativeService($provider))->generateFeaturedMeasure(
                $content,
                $this->featuredReport(42, 90, 'pending'),
                90,
                'es',
            );
            self::fail('Manual text should require explicit replacement.');
        } catch (\InvalidArgumentException) {
            self::assertSame('Texto antiguo sin origen', $content->getFeaturedMeasures()[0]['text']);
            self::assertArrayNotHasKey('origin', $content->getFeaturedMeasures()[0]);
        }
    }

    public function testExplicitReplacementAndAiRegenerationSetAiOrigin(): void
    {
        $provider = new class implements AiReportProviderInterface {
            public int $calls = 0;

            public function generate(AiReportRequest $request): AiReportResult
            {
                ++$this->calls;

                return new AiReportResult('Unused', [
                    new AiReportCategorySummary('featured_measure', 'Relato '.$this->calls),
                ], [], 'Unused');
            }
        };
        $content = (new FinalReportContent())->setProject($this->project(43))->setFeaturedMeasures([[
            'planMeasureId' => 91,
            'text' => 'Manual',
            'origin' => 'manual',
        ]]);
        $service = new FinalReportNarrativeService($provider);

        $service->generateFeaturedMeasure($content, $this->featuredReport(43, 91, 'in_progress'), 91, 'es', true);
        $service->generateFeaturedMeasure($content, $this->featuredReport(43, 91, 'in_progress'), 91, 'es');

        self::assertSame(2, $provider->calls);
        self::assertSame('Relato 2', $content->getFeaturedMeasures()[0]['text']);
        self::assertSame('ai', $content->getFeaturedMeasures()[0]['origin']);
    }

    public function testInvalidFeaturedResultsAndProviderFailurePreservePreviousContent(): void
    {
        $results = [
            new AiReportResult('Unused', [new AiReportCategorySummary('featured_measure', '   ')], [], 'Unused'),
            new AiReportResult('Unused', [new AiReportCategorySummary('featured_measure', str_repeat('x', 2001))], [], 'Unused'),
            new AiReportResult('Unused', [new AiReportCategorySummary('other', 'Wrong key')], [], 'Unused'),
        ];
        foreach ($results as $result) {
            $provider = $this->createStub(AiReportProviderInterface::class);
            $provider->method('generate')->willReturn($result);
            $content = (new FinalReportContent())->setProject($this->project(44))->setFeaturedMeasures([[
                'planMeasureId' => 92,
                'text' => 'Relato IA anterior',
                'origin' => 'ai',
            ]]);

            try {
                (new FinalReportNarrativeService($provider))->generateFeaturedMeasure(
                    $content,
                    $this->featuredReport(44, 92, 'implemented'),
                    92,
                    'es',
                );
                self::fail('Invalid AI result should be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame('Relato IA anterior', $content->getFeaturedMeasures()[0]['text']);
                self::assertSame('ai', $content->getFeaturedMeasures()[0]['origin']);
            }
        }

        $provider = $this->createStub(AiReportProviderInterface::class);
        $provider->method('generate')->willThrowException(new AiTimeoutException('timeout'));
        $content = (new FinalReportContent())->setProject($this->project(44))->setFeaturedMeasures([[
            'planMeasureId' => 92,
            'text' => 'Relato IA anterior',
            'origin' => 'ai',
        ]]);
        try {
            (new FinalReportNarrativeService($provider))->generateFeaturedMeasure(
                $content,
                $this->featuredReport(44, 92, 'implemented'),
                92,
                'es',
            );
            self::fail('Provider failure should be propagated.');
        } catch (AiTimeoutException) {
            self::assertSame('Relato IA anterior', $content->getFeaturedMeasures()[0]['text']);
            self::assertSame('ai', $content->getFeaturedMeasures()[0]['origin']);
        }
    }

    public function testRejectsNonFeaturedOrForeignPlanMeasureBeforeProviderCall(): void
    {
        $provider = $this->createMock(AiReportProviderInterface::class);
        $provider->expects(self::never())->method('generate');
        $service = new FinalReportNarrativeService($provider);
        $project = $this->project(45);

        foreach ([
            [(new FinalReportContent())->setProject($project), $this->featuredReport(45, 93, 'pending'), 93],
            [(new FinalReportContent())->setProject($project)->setFeaturedMeasures([['planMeasureId' => 94, 'text' => null]]), $this->featuredReport(45, 93, 'pending'), 94],
            [(new FinalReportContent())->setProject($this->project(46))->setFeaturedMeasures([['planMeasureId' => 95, 'text' => null]]), $this->featuredReport(45, 95, 'pending'), 95],
        ] as [$content, $report, $id]) {
            try {
                $service->generateFeaturedMeasure($content, $report, $id, 'es');
                self::fail('Invalid target measure should be rejected.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
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

    /** @return array<string, mixed> */
    private function featuredReport(int $projectId, int $planMeasureId, string $state): array
    {
        return [
            'project' => [
                'id' => $projectId,
                'name' => 'Proyecto final',
                'type' => 'rodaje',
                'country' => 'ES',
                'mainLocation' => 'Madrid',
                'phases' => [['code' => 'production']],
            ],
            'plan' => ['measures' => [[
                'planMeasureId' => $planMeasureId,
                'measure' => [
                    'name' => 'Movilidad sostenible',
                    'description' => 'Reducir desplazamientos',
                    'implementationGuidance' => 'Priorizar transporte colectivo',
                ],
                'decision' => [
                    'applicable' => true,
                    'willImplement' => true,
                    'primary' => 'true',
                    'critical' => false,
                ],
            ]]],
            'implementation' => ['measures' => [[
                'planMeasureId' => $planMeasureId,
                'state' => $state,
                'actionTaken' => 'Acción documentada',
                'incident' => null,
                'observations' => 'Observación factual',
                'verification' => true,
                'responsibleCrewMembers' => [['name' => 'Dato personal excluido']],
            ]]],
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
