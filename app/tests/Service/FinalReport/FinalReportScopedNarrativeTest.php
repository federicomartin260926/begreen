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

final class FinalReportScopedNarrativeTest extends TestCase
{
    private function content(): FinalReportContent
    {
        $project = new Project();
        (new \ReflectionProperty(Project::class, 'id'))->setValue($project, 79);
        return (new FinalReportContent())->setProject($project);
    }

    private function report(): array
    {
        return [
            'project' => ['id' => 79],
            'plan' => ['status' => 'in_progress', 'measures' => []],
            'implementation' => ['measures' => []],
            'emissions' => ['available' => false],
            'ods' => [],
        ];
    }

    public function testOnlyRequestedImplementationIsPersisted(): void
    {
        $content = $this->content()->setManualTexts([
            'executive_summary' => ['text' => 'Keep executive', 'origin' => 'manual'],
            'conclusion' => ['text' => 'Keep conclusion', 'origin' => 'manual'],
        ]);
        $provider = $this->createMock(AiReportProviderInterface::class);
        $provider->expects(self::once())->method('generate')->with(self::callback(
            static fn (AiReportRequest $request): bool =>
                $request->purpose === 'final_sustainability_report'
                && $request->sectionKeys === ['implementation']
                && ($request->factualContext['scope'] ?? null) === 'single_section'
                && ($request->factualContext['targetSection'] ?? null) === 'implementation',
        ))->willReturn(new AiReportResult('Unused', [
            new AiReportCategorySummary('implementation', 'Implementation only'),
        ], [], 'Unused'));

        $result = (new FinalReportNarrativeService($provider))->generateSection(
            $content, $this->report(), 'implementation', 'es',
        );
        self::assertSame(['section' => 'implementation', 'text' => 'Implementation only', 'origin' => 'ai'], $result);
        self::assertSame('Keep executive', $content->getManualTexts()['executive_summary']['text']);
        self::assertSame('Keep conclusion', $content->getManualTexts()['conclusion']['text']);
    }

    public function testExecutiveSummaryUsesOnlyGeneralConclusion(): void
    {
        $content = $this->content();
        $provider = $this->createMock(AiReportProviderInterface::class);
        $provider->expects(self::once())->method('generate')->with(self::callback(
            static fn (AiReportRequest $request): bool => $request->sectionKeys === []
                && ($request->factualContext['targetSection'] ?? null) === 'executive_summary',
        ))->willReturn(new AiReportResult('Executive only', [], [], 'Unused'));
        (new FinalReportNarrativeService($provider))->generateSection($content, $this->report(), 'executive_summary', 'en');
        self::assertSame('Executive only', $content->getManualTexts()['executive_summary']['text']);
    }

    public function testManualTextProtectedBeforeCallingProvider(): void
    {
        $content = $this->content()->setManualTexts([
            'implementation' => ['text' => 'Keep manual', 'origin' => 'manual'],
        ]);
        $provider = $this->createMock(AiReportProviderInterface::class);
        $provider->expects(self::never())->method('generate');
        $this->expectException(\InvalidArgumentException::class);
        (new FinalReportNarrativeService($provider))->generateSection($content, $this->report(), 'implementation', 'es');
    }

    public function testInvalidSectionDoesNotCallProvider(): void
    {
        $provider = $this->createMock(AiReportProviderInterface::class);
        $provider->expects(self::never())->method('generate');
        $this->expectException(\InvalidArgumentException::class);
        (new FinalReportNarrativeService($provider))->generateSection($this->content(), $this->report(), 'conclusion', 'es');
    }
}
