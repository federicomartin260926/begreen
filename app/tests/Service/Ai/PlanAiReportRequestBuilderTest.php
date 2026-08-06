<?php

namespace App\Tests\Service\Ai;

use App\Entity\Category;
use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Exception\Ai\AiReportRequestException;
use App\Service\Ai\AiReportConfiguration;
use App\Service\Ai\AiReportMeasureDecision;
use App\Service\Ai\AnthropicReportConfiguration;
use App\Service\Ai\OpenAiReportConfiguration;
use App\Service\Ai\PlanAiReportRequestBuilder;
use App\Service\SustainabilityPlanMeasureOrderer;
use Doctrine\Persistence\ManagerRegistry;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;
use Gedmo\Translatable\Entity\Translation;
use PHPUnit\Framework\TestCase;

final class PlanAiReportRequestBuilderTest extends TestCase
{
    public function testBuildsOrderedElaborationRequestAndExcludesIneligibleData(): void
    {
        $energy = $this->category(20, 'Energía', 20);
        $office = $this->category(10, 'Oficina', 10);
        $emptyCategory = $this->category(30, 'Sin medidas válidas', 30);
        $plan = new Plan();

        $later = $this->planMeasure(
            $this->measure(102, 'Título largo B', 'Título corto B', 'Descripción B', 5, $office, 20),
            true,
            true,
            'Observación B',
            true,
        );
        $later
            ->setImplemented(true)
            ->setActionTaken('ACCION_IMPLANTACION_PRIVADA')
            ->setExecutionIncident('INCIDENCIA_IMPLANTACION_PRIVADA')
            ->setInternalNotes('NOTA_INTERNA_SECRETA')
            ->setEvidence('EVIDENCIA_PRIVADA');

        $plan
            ->addPlanMeasure($this->planMeasure(
                $this->measure(201, 'Energía', null, 'Descripción energía', 4, $energy, 10),
                true,
                false,
                'Observación energía',
                false,
            ))
            ->addPlanMeasure($later)
            ->addPlanMeasure($this->planMeasure(
                $this->measure(101, 'Título A', null, 'Descripción A', 4, $office, 10),
                false,
                null,
                'Observación A',
                false,
            ))
            ->addPlanMeasure($this->planMeasure(
                $this->measure(301, 'Puntuación baja', null, 'No debe aparecer', 3, $emptyCategory, 10),
                true,
                true,
            ))
            ->addPlanMeasure($this->planMeasure(
                $this->measure(302, 'Pendiente', null, 'No debe aparecer', 5, $emptyCategory, 20),
                true,
                null,
            ))
            ->addPlanMeasure($this->planMeasure(
                $this->measure(303, 'Sin categoría', null, 'No debe aparecer', 5, null, 30),
                true,
                true,
            ));

        $request = $this->builder()->build($plan, 'es_ES');

        self::assertSame('es', $request->locale);
        self::assertSame(['category:10', 'category:20'], array_column($request->categories, 'key'));
        self::assertSame(['Título A', 'Título corto B'], array_column($request->categories[0]->measures, 'title'));
        self::assertSame(
            [AiReportMeasureDecision::NOT_APPLICABLE, AiReportMeasureDecision::PLANNED],
            array_column($request->categories[0]->measures, 'decision'),
        );
        self::assertSame(['measure:101', 'measure:102'], array_column($request->categories[0]->measures, 'key'));
        self::assertSame([false, true], array_column($request->categories[0]->measures, 'critical'));
        self::assertSame('Descripción B', $request->categories[0]->measures[1]->description);
        self::assertSame('Observación B', $request->categories[0]->measures[1]->observations);
        self::assertSame(AiReportMeasureDecision::NOT_PLANNED, $request->categories[1]->measures[0]->decision);

        $serialized = json_encode($request, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('NOTA_INTERNA_SECRETA', $serialized);
        self::assertStringNotContainsString('EVIDENCIA_PRIVADA', $serialized);
        self::assertStringNotContainsString('ACCION_IMPLANTACION_PRIVADA', $serialized);
        self::assertStringNotContainsString('INCIDENCIA_IMPLANTACION_PRIVADA', $serialized);
        self::assertStringNotContainsString('Sin medidas válidas', $serialized);
    }

    public function testUsesExactEnglishTranslationsWithoutLanguageFallback(): void
    {
        $category = $this->category(10, 'Energía', 10);
        $translated = $this->measure(101, 'Nombre ES', 'Título corto ES', 'Descripción ES', 5, $category, 10);
        $withoutTitle = $this->measure(102, 'Solo español', null, 'Descripción ES', 5, $category, 20);
        $plan = (new Plan())
            ->addPlanMeasure($this->planMeasure($translated, true, true))
            ->addPlanMeasure($this->planMeasure($withoutTitle, true, true));

        $translations = [
            spl_object_id($category) => ['en' => ['name' => 'Energy']],
            spl_object_id($translated) => ['en' => [
                'name' => 'Long title EN',
                'nameReview' => 'Short title EN',
                'description' => 'Description EN',
            ]],
            spl_object_id($withoutTitle) => ['en' => ['description' => 'Description EN']],
        ];

        $request = $this->builder($translations)->build($plan, 'EN-gb');

        self::assertSame('en', $request->locale);
        self::assertSame('Energy', $request->categories[0]->name);
        self::assertCount(1, $request->categories[0]->measures);
        self::assertSame('Short title EN', $request->categories[0]->measures[0]->title);
        self::assertSame('Description EN', $request->categories[0]->measures[0]->description);
    }

    public function testRejectsUnsupportedLocale(): void
    {
        $this->expectException(AiReportRequestException::class);
        $this->expectExceptionMessage('The requested AI report locale is not supported.');

        $this->builder()->build(new Plan(), 'fr');
    }

    public function testRejectsRequestWithoutEligibleMeasures(): void
    {
        $category = $this->category(10, 'Energía', 10);
        $plan = (new Plan())->addPlanMeasure($this->planMeasure(
            $this->measure(101, 'Medida', null, 'Descripción', 3, $category, 10),
            true,
            true,
        ));

        $this->expectException(AiReportRequestException::class);
        $this->expectExceptionMessage('The plan has no eligible measures for the AI report.');

        $this->builder()->build($plan, 'es');
    }

    /** @param array<int, array<string, array<string, string>>> $translations */
    private function builder(array $translations = []): PlanAiReportRequestBuilder
    {
        $translationRepository = $this->createMock(TranslationRepository::class);
        $translationRepository->method('findTranslations')->willReturnCallback(
            static fn (object $entity): array => $translations[spl_object_id($entity)] ?? [],
        );

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getRepository')
            ->with(Translation::class)
            ->willReturn($translationRepository);

        return new PlanAiReportRequestBuilder(
            new AiReportConfiguration(
                'openai',
                30,
                4,
                '',
                new OpenAiReportConfiguration('', '', ''),
                new AnthropicReportConfiguration('', '', '', '', 1000),
            ),
            new SustainabilityPlanMeasureOrderer(),
            $doctrine,
        );
    }

    private function category(int $id, string $name, int $sortOrder): Category
    {
        $category = (new Category())->setName($name)->setSortOrder($sortOrder);
        $this->setId($category, $id);

        return $category;
    }

    private function measure(
        int $id,
        string $name,
        ?string $nameReview,
        ?string $description,
        int $score,
        ?Category $category,
        int $sortOrder,
    ): Measure {
        $measure = (new Measure())
            ->setName($name)
            ->setNameReview($nameReview)
            ->setDescription($description)
            ->setScore($score)
            ->setCategory($category)
            ->setSortOrder($sortOrder);
        $this->setId($measure, $id);

        return $measure;
    }

    private function planMeasure(
        Measure $measure,
        ?bool $applicable,
        ?bool $willImplement,
        ?string $observations = null,
        ?bool $critical = null,
    ): PlanMeasure {
        return (new PlanMeasure())
            ->setMeasure($measure)
            ->setIsApplicable($applicable)
            ->setWillImplement($willImplement)
            ->setObservations($observations)
            ->setIsCritical($critical);
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
