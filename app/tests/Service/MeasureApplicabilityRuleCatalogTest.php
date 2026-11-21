<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\Protocol;
use App\Service\MeasureApplicabilityRuleCatalog;
use PHPUnit\Framework\TestCase;

final class MeasureApplicabilityRuleCatalogTest extends TestCase
{
    private MeasureApplicabilityRuleCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new MeasureApplicabilityRuleCatalog(dirname(__DIR__, 2));
    }

    public function testContainsTheApprovedFiltersForAllProtocols(): void
    {
        self::assertSame(
            array_map(static fn (int $number): string => sprintf('F%02d', $number), range(1, 14)),
            array_column($this->catalog->filtersForProtocol('be-green-my-film'), 'code'),
        );
        self::assertSame(
            array_map(static fn (int $number): string => sprintf('E%02d', $number), range(1, 12)),
            array_column($this->catalog->filtersForProtocol('be-green-my-event'), 'code'),
        );
        self::assertSame(
            array_map(static fn (int $number): string => sprintf('A%02d', $number), range(1, 7)),
            array_column($this->catalog->filtersForProtocol('be-green-my-animation'), 'code'),
        );
    }

    public function testFilm75AllowsConditionalNotApplicableButBlockedMeasureOnlyAllowsYes(): void
    {
        $film75 = $this->filmMeasure(75);
        self::assertSame('N/A CONDICIONAL', $this->catalog->ruleForMeasure($film75)['responseMode']);
        self::assertTrue($this->catalog->allowsDecision($film75, 'na'));

        $blocked = $this->filmMeasure(6);
        self::assertTrue($this->catalog->allowsDecision($blocked, 'true'));
        self::assertFalse($this->catalog->allowsDecision($blocked, 'false'));
        self::assertFalse($this->catalog->allowsDecision($blocked, 'na'));
    }

    public function testAnimationContextualFiltersPermitProvisionalConditionalNotApplicable(): void
    {
        // A05 is context, not an automatic "No" filter. Its dependent measures
        // must still offer the per-measure documented N/A decision.
        $a05Measure = (new Measure())
            ->setProtocol((new Protocol())->setCode('be-green-my-animation'))
            ->setCatalogId('ANI-U064');
        self::assertTrue($this->catalog->allowsDecision($a05Measure, 'na'));

        $a03A06Measure = (new Measure())
            ->setProtocol((new Protocol())->setCode('be-green-my-animation'))
            ->setCatalogId('ANI-U195');
        self::assertTrue($this->catalog->allowsDecision($a03A06Measure, 'na'));

        // A02 is an objective compatibility filter and does not permit an
        // arbitrary manual N/A when the selected technique matches.
        $a02Measure = (new Measure())
            ->setProtocol((new Protocol())->setCode('be-green-my-animation'))
            ->setCatalogId('ANI-U202');
        self::assertFalse($this->catalog->allowsDecision($a02Measure, 'na'));
    }

    public function testAnimation77HasNoA04Dependency(): void
    {
        $measure = (new Measure())
            ->setProtocol((new Protocol())->setCode('be-green-my-animation'))
            ->setCatalogId('ANI-U280');

        $rule = $this->catalog->ruleForMeasure($measure);
        self::assertSame(77, $rule['number']);
        self::assertSame([], $rule['filterCodes']);
        self::assertTrue($this->catalog->allowsDecision($measure, 'na'));
    }

    private function filmMeasure(int $number): Measure
    {
        return (new Measure())
            ->setProtocol((new Protocol())->setCode('be-green-my-film'))
            ->setSourceRow($number + 2);
    }
}
