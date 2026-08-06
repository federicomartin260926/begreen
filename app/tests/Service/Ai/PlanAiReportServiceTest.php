<?php

namespace App\Tests\Service\Ai;

use App\Entity\Category;
use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Service\Ai\AiReportConfiguration;
use App\Service\Ai\AiReportContextHasher;
use App\Service\Ai\AiReportLockInterface;
use App\Service\Ai\AiReportPromptBuilder;
use App\Service\Ai\AiReportPromptConfiguration;
use App\Service\Ai\AiReportProviderInterface;
use App\Service\Ai\AiReportResultValidator;
use App\Service\Ai\AiReportStorage;
use App\Service\Ai\AnthropicReportConfiguration;
use App\Service\Ai\Dto\AiReportCategorySummary;
use App\Service\Ai\Dto\AiReportRequest;
use App\Service\Ai\Dto\AiReportResult;
use App\Service\Ai\Dto\AiStoredReport;
use App\Service\Ai\OpenAiReportConfiguration;
use App\Service\Ai\PlanAiReportRequestBuilder;
use App\Service\Ai\PlanAiReportService;
use App\Service\SustainabilityPlanMeasureOrderer;
use Doctrine\Persistence\ManagerRegistry;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;
use Gedmo\Translatable\Entity\Translation;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class PlanAiReportServiceTest extends TestCase
{
    private string $storageDirectory;

    protected function setUp(): void
    {
        $this->storageDirectory = sys_get_temp_dir().'/begreen-plan-ai-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->storageDirectory);
    }

    public function testGeneratesPersistsAndThenReusesWithoutCallingProviderAgain(): void
    {
        [$plan] = $this->plan();
        $provider = new CountingAiReportProvider();
        $storage = new AiReportStorage($this->storageDirectory, new CollectingAiLogger());
        $service = $this->service($storage, $provider);

        $first = $service->getOrGenerate($plan, 'es_ES');
        $second = $service->getOrGenerate($plan, 'es');

        self::assertSame(1, $provider->calls);
        self::assertEquals($first, $second);
        self::assertFileExists($storage->pathFor(123, 'es'));
        $json = (string) file_get_contents($storage->pathFor(123, 'es'));
        self::assertStringNotContainsString('Observación sensible.', $json);
        self::assertStringNotContainsString('Descripción funcional.', $json);
    }

    public function testOnlyRelevantElaborationChangesRegenerate(): void
    {
        [$plan, $planMeasure] = $this->plan();
        $provider = new CountingAiReportProvider();
        $service = $this->service(
            new AiReportStorage($this->storageDirectory, new CollectingAiLogger()),
            $provider,
        );

        $service->getOrGenerate($plan, 'es');
        $planMeasure
            ->setImplemented(true)
            ->setActionTaken('Acción ejecutada que no pertenece a Elaboración.')
            ->setExecutionIncident('Incidencia operativa privada.')
            ->setEvidence('/private/evidence.pdf');
        $service->getOrGenerate($plan, 'es');
        self::assertSame(1, $provider->calls);

        $planMeasure->setObservations('Observación de Elaboración modificada.');
        $service->getOrGenerate($plan, 'es');
        self::assertSame(2, $provider->calls);
    }

    public function testCorruptJsonRegeneratesWithoutLoggingItsContent(): void
    {
        [$plan] = $this->plan();
        $logger = new CollectingAiLogger();
        $provider = new CountingAiReportProvider();
        $storage = new AiReportStorage($this->storageDirectory, $logger);
        $service = $this->service($storage, $provider, logger: $logger);

        $service->getOrGenerate($plan, 'es');
        file_put_contents($storage->pathFor(123, 'es'), '{CORRUPT_SECRET');
        $service->getOrGenerate($plan, 'es');

        self::assertSame(2, $provider->calls);
        self::assertStringNotContainsString('CORRUPT_SECRET', json_encode($logger->records, JSON_THROW_ON_ERROR));
        self::assertContains('ai_report_storage_invalid', array_column($logger->contexts(), 'event'));
    }

    public function testRechecksStorageInsideLockAndAvoidsDuplicateProviderCall(): void
    {
        [$plan] = $this->plan();
        $configuration = $this->configuration();
        $logger = new CollectingAiLogger();
        $storage = new AiReportStorage($this->storageDirectory, $logger);
        $provider = new CountingAiReportProvider();
        $builder = $this->builder($configuration);
        $request = $builder->build($plan, 'es');
        $hasher = new AiReportContextHasher($this->promptBuilder());
        $lock = new HookAiReportLock(function () use ($storage, $hasher, $request): void {
            $storage->write(new AiStoredReport(
                AiStoredReport::VERSION,
                123,
                'es',
                'openai',
                'model-a',
                $hasher->promptVersion(),
                $hasher->hash($request),
                '2026-08-06T10:00:00+02:00',
                'Generado por otro proceso.',
                [['categoryKey' => 'category:10', 'summary' => 'Resumen concurrente.']],
            ));
        });

        $result = $this->service($storage, $provider, $lock)->getOrGenerate($plan, 'es');

        self::assertSame(0, $provider->calls);
        self::assertSame(['ai-report:123:es'], $lock->keys);
        self::assertSame('Generado por otro proceso.', $result->generalConclusion);
    }

    private function service(
        AiReportStorage $storage,
        CountingAiReportProvider $provider,
        ?AiReportLockInterface $lock = null,
        ?CollectingAiLogger $logger = null,
    ): PlanAiReportService {
        $configuration = $this->configuration();
        $promptBuilder = $this->promptBuilder();

        return new PlanAiReportService(
            $this->builder($configuration),
            new AiReportContextHasher($promptBuilder),
            $storage,
            $provider,
            $configuration,
            new AiReportResultValidator(),
            $lock ?? new ImmediateAiReportLock(),
            new MockClock('2026-08-06 10:00:00', 'Europe/Madrid'),
            $logger ?? new CollectingAiLogger(),
        );
    }

    private function builder(AiReportConfiguration $configuration): PlanAiReportRequestBuilder
    {
        $translationRepository = $this->createMock(TranslationRepository::class);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getRepository')->with(Translation::class)->willReturn($translationRepository);

        return new PlanAiReportRequestBuilder(
            $configuration,
            new SustainabilityPlanMeasureOrderer(),
            $doctrine,
        );
    }

    private function promptBuilder(): AiReportPromptBuilder
    {
        return new AiReportPromptBuilder(new AiReportPromptConfiguration(dirname(__DIR__, 3).'/config/ai_report_prompt.yaml'));
    }

    private function configuration(): AiReportConfiguration
    {
        return new AiReportConfiguration(
            'openai',
            30,
            4,
            '',
            new OpenAiReportConfiguration('', 'model-a', ''),
            new AnthropicReportConfiguration('', 'unused-anthropic', '', '', 1000),
        );
    }

    /** @return array{Plan, PlanMeasure} */
    private function plan(): array
    {
        $category = (new Category())->setName('Energía')->setSortOrder(10);
        $measure = (new Measure())
            ->setName('Título largo')
            ->setNameReview('Título corto')
            ->setDescription('Descripción funcional.')
            ->setScore(5)
            ->setSortOrder(10)
            ->setCategory($category);
        $planMeasure = (new PlanMeasure())
            ->setMeasure($measure)
            ->setIsApplicable(true)
            ->setWillImplement(true)
            ->setIsCritical(true)
            ->setObservations('Observación sensible.');
        $plan = (new Plan())->addPlanMeasure($planMeasure);

        $this->setId($plan, 123);
        $this->setId($category, 10);
        $this->setId($measure, 100);

        return [$plan, $planMeasure];
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}

final class CountingAiReportProvider implements AiReportProviderInterface
{
    public int $calls = 0;

    public function generate(AiReportRequest $request): AiReportResult
    {
        ++$this->calls;

        return new AiReportResult(
            'Conclusión generada.',
            array_map(
                static fn ($category): AiReportCategorySummary => new AiReportCategorySummary(
                    $category->key,
                    'Resumen de '.$category->key.'.',
                ),
                $request->categories,
            ),
        );
    }
}

final class ImmediateAiReportLock implements AiReportLockInterface
{
    public function synchronized(string $key, callable $callback): mixed
    {
        return $callback();
    }
}

final class HookAiReportLock implements AiReportLockInterface
{
    /** @var list<string> */
    public array $keys = [];

    public function __construct(private readonly \Closure $beforeCallback)
    {
    }

    public function synchronized(string $key, callable $callback): mixed
    {
        $this->keys[] = $key;
        ($this->beforeCallback)();

        return $callback();
    }
}

final class CollectingAiLogger extends AbstractLogger
{
    /** @var list<array{level:mixed, message:string, context:array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function contexts(): array
    {
        return array_column($this->records, 'context');
    }
}
