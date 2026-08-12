<?php

namespace App\Service\Ai;

use App\Entity\Plan;
use App\Exception\Ai\AiInvalidStructureException;
use App\Exception\Ai\AiReportRequestException;
use App\Service\Ai\Dto\AiReportCategory;
use App\Service\Ai\Dto\AiReportCategorySummary;
use App\Service\Ai\Dto\AiReportRequest;
use App\Service\Ai\Dto\AiReportResult;
use App\Service\Ai\Dto\AiStoredReport;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class PlanAiReportService
{
    public function __construct(
        private PlanAiReportRequestBuilder $requestBuilder,
        private AiReportContextHasher $contextHasher,
        private AiReportStorage $storage,
        private AiReportProviderInterface $provider,
        private AiReportConfiguration $configuration,
        private AiReportResultValidator $resultValidator,
        private AiReportLockInterface $lock,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private ?AiReportSettingResolver $settingResolver = null,
    ) {
    }

    public function getOrGenerate(Plan $plan, string $locale): AiReportResult
    {
        $planId = $plan->getId();
        if ($planId === null || $planId <= 0) {
            throw new AiReportRequestException('The plan must be persisted before generating an AI report.');
        }

        $request = $this->requestBuilder->build($plan, $locale);
        $contextHash = $this->contextHasher->hash($request);

        $stored = $this->freshResult($this->storage->read($planId, $request->locale), $planId, $request, $contextHash);
        if ($stored instanceof AiReportResult) {
            $this->logLifecycle('reused', $planId, $request->locale);

            return $stored;
        }

        return $this->lock->synchronized(
            sprintf('ai-report:%d:%s', $planId, $request->locale),
            function () use ($planId, $request, $contextHash): AiReportResult {
                $stored = $this->freshResult($this->storage->read($planId, $request->locale), $planId, $request, $contextHash);
                if ($stored instanceof AiReportResult) {
                    $this->logLifecycle('reused_after_lock', $planId, $request->locale);

                    return $stored;
                }

                $result = $this->validateResult($this->provider->generate($request), $request);
                $this->storage->write(new AiStoredReport(
                    AiStoredReport::VERSION,
                    $planId,
                    $request->locale,
                    $this->providerName(),
                    $this->modelName(),
                    $this->contextHasher->promptVersion(),
                    $contextHash,
                    $this->clock->now()
                        ->setTimezone(new \DateTimeZone('Europe/Madrid'))
                        ->format(\DateTimeInterface::ATOM),
                    $result->generalConclusion,
                    array_map(
                        static fn (AiReportCategorySummary $summary): array => [
                            'categoryKey' => $summary->categoryKey,
                            'summary' => $summary->summary,
                        ],
                        $result->categorySummaries,
                    ),
                    $result->finalConclusion,
                ));

                $this->logLifecycle('regenerated', $planId, $request->locale);

                return $result;
            },
        );
    }

    private function freshResult(
        ?AiStoredReport $stored,
        int $planId,
        AiReportRequest $request,
        string $contextHash,
    ): ?AiReportResult {
        if (
            !$stored instanceof AiStoredReport
            || $stored->version !== AiStoredReport::VERSION
            || $stored->planId !== $planId
            || $stored->locale !== $request->locale
            || $stored->provider !== $this->providerName()
            || $stored->model !== $this->modelName()
            || $stored->promptVersion !== $this->contextHasher->promptVersion()
            || !hash_equals($stored->contextHash, $contextHash)
        ) {
            return null;
        }

        try {
            return $this->resultValidator->validate($stored->resultData(), $this->categoryKeys($request));
        } catch (AiInvalidStructureException $exception) {
            $this->logger->warning('Stored AI report result is invalid.', [
                'event' => 'ai_report_result_invalid',
                'plan_id' => $stored->planId,
                'locale' => $stored->locale,
                'provider' => $this->providerName(),
                'model' => $this->modelName(),
                'error_type' => $exception::class,
            ]);

            return null;
        }
    }

    private function validateResult(AiReportResult $result, AiReportRequest $request): AiReportResult
    {
        return $this->resultValidator->validate([
            'generalConclusion' => $result->generalConclusion,
            'categorySummaries' => array_map(
                static fn (AiReportCategorySummary $summary): array => [
                    'categoryKey' => $summary->categoryKey,
                    'summary' => $summary->summary,
                ],
                $result->categorySummaries,
            ),
            'finalConclusion' => $result->finalConclusion,
        ], $this->categoryKeys($request));
    }

    /** @return list<string> */
    private function categoryKeys(AiReportRequest $request): array
    {
        return array_map(static fn (AiReportCategory $category): string => $category->key, $request->categories);
    }

    private function providerName(): string
    {
        return $this->settingResolver?->resolve()->provider
            ?? strtolower(trim($this->configuration->provider));
    }

    private function modelName(): string
    {
        return $this->settingResolver?->resolve()->model()
            ?? trim($this->configuration->model());
    }

    private function logLifecycle(string $event, int $planId, string $locale): void
    {
        $this->logger->info('AI report lifecycle event.', [
            'event' => 'ai_report_'.$event,
            'plan_id' => $planId,
            'locale' => $locale,
            'provider' => $this->providerName(),
            'model' => $this->modelName(),
        ]);
    }
}
