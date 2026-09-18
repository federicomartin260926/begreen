<?php

declare(strict_types=1);

namespace App\Tests\Service\Bgos;

use App\Service\Bgos\BgosEmissionEntryContextResolver;
use App\Service\Bgos\BgosSubcategoryCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class BgosEmissionEntryContextResolverTest extends KernelTestCase
{
    private BgosEmissionEntryContextResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resolver = new BgosEmissionEntryContextResolver(
            self::getContainer()->get(BgosSubcategoryCatalog::class),
        );
    }

    public function testResolvesValidContextAndSafeReturnQuery(): void
    {
        $context = $this->resolver->resolve(
            $this->request('energy', 'electricity'),
            'energy',
        );

        self::assertNotNull($context);
        self::assertSame('2026-09-17', $context->date->format('Y-m-d'));
        self::assertSame('day', $context->view);
        self::assertSame('energy', $context->categoryKey);
        self::assertSame('electricity', $context->subcategoryKey);
        self::assertSame([
            'view' => 'day',
            'date' => '2026-09-17',
        ], $context->returnQuery());
    }

    public function testInvalidDateOrViewDoesNotResolve(): void
    {
        self::assertNull($this->resolver->resolve(
            $this->request('energy', 'electricity', date: '2026-02-30'),
            'energy',
        ));
        self::assertNull($this->resolver->resolve(
            $this->request('energy', 'electricity', view: 'external'),
            'energy',
        ));
    }

    public function testUnknownOrMismatchedSubcategoryDoesNotResolve(): void
    {
        self::assertNull($this->resolver->resolve(
            $this->request('energy', 'invented'),
            'energy',
        ));
        self::assertNull($this->resolver->resolve(
            $this->request('energy', 'electricity'),
            'water',
        ));
    }

    /** @param array<string, string|null> $expectedDefaults */
    #[DataProvider('mappingProvider')]
    public function testProvidesCategorySpecificDefaults(
        string $categoryKey,
        string $subcategoryKey,
        array $expectedDefaults,
    ): void {
        $context = $this->resolver->resolve(
            $this->request($categoryKey, $subcategoryKey),
            $categoryKey,
        );

        self::assertNotNull($context);
        self::assertSame([
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-17',
            ...$expectedDefaults,
        ], $context->formDefaults());
    }

    public static function mappingProvider(): iterable
    {
        yield 'energy' => ['energy', 'electricity', ['family' => 'electricity']];
        yield 'water' => ['water', 'limpieza', ['waterUseType' => 'limpieza']];
        yield 'accommodation' => ['accommodation', 'hotel', ['accommodationType' => 'hotel']];
        yield 'catering' => ['catering', 'meal', ['activityType' => 'meal']];
        yield 'material' => ['materials', 'carton', ['activity' => 'Cartón', 'family' => 'cardboard']];
        yield 'transport freight' => ['transport', 'freight', ['category' => 'freight']];
        yield 'transport people' => ['transport', 'people', ['category' => null]];
        yield 'waste without country' => ['waste', 'aceites-usados', []];
    }

    private function request(
        string $categoryKey,
        string $subcategoryKey,
        string $date = '2026-09-17',
        string $view = 'day',
    ): Request {
        return new Request([
            'bgosDate' => $date,
            'bgosView' => $view,
            'bgosCategory' => $categoryKey,
            'bgosSubcategory' => $subcategoryKey,
        ]);
    }
}
