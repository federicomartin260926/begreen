<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Service\ProjectCommercialPricing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectCommercialPricingTest extends TestCase
{
    private ProjectCommercialPricing $pricing;

    protected function setUp(): void
    {
        $this->pricing = new ProjectCommercialPricing();
    }

    public function testProjectTierDefaultsToBasicAndRejectsUnknownValues(): void
    {
        $project = new Project();
        self::assertSame(Project::COMMERCIAL_TIER_BASIC, $project->getCommercialTier());

        $this->expectException(\InvalidArgumentException::class);
        $project->setCommercialTier('enterprise');
    }

    public function testPricingFingerprintChangesWithPhysicalAttendeeCount(): void
    {
        $project = (new Project())->setType('evento')->setEventModality('hibrido')->setEventAttendeesCount(100);
        $before = $this->pricing->getPricingFingerprint($project);
        self::assertSame(64, strlen($before));
        self::assertSame($before, $this->pricing->getPricingFingerprint($project));
        $project->setEventAttendeesCount(101);
        self::assertNotSame($before, $this->pricing->getPricingFingerprint($project));
    }

    #[DataProvider('filmingPrices')]
    public function testFilmingPrices(string $filmingType, int $standard, int $pro, string $band): void
    {
        $project = (new Project())->setType('rodaje')->setFilmingType($filmingType);

        self::assertSame(0, $this->pricing->getAmountCents($project, 'basic'));
        self::assertSame($standard, $this->pricing->getAmountCents($project, 'standard'));
        self::assertSame($pro, $this->pricing->getAmountCents($project, 'pro'));
        self::assertSame($band, $this->pricing->getPriceBand($project));
        self::assertSame($pro - $standard, $this->pricing->getUpgradeAmountCents($project, 'standard', 'pro'));
    }

    public static function filmingPrices(): iterable
    {
        yield 'feature' => ['feature', 49900, 99900, ProjectCommercialPricing::BAND_MID];
        yield 'tv series' => ['tv_series', 74900, 149900, ProjectCommercialPricing::BAND_HIGH];
        yield 'tv program stays low' => ['tv_program', 24900, 49900, ProjectCommercialPricing::BAND_LOW];
        yield 'other valid filming type' => ['short', 24900, 49900, ProjectCommercialPricing::BAND_LOW];
    }

    #[DataProvider('eventPrices')]
    public function testEventPrices(string $modality, ?int $attendees, int $standard, int $pro, string $band): void
    {
        $project = (new Project())
            ->setType('evento')
            ->setEventModality($modality)
            ->setEventAttendeesCount($attendees)
            ->setEventOnlineConnections(50000);

        self::assertSame($standard, $this->pricing->getAmountCents($project, 'standard'));
        self::assertSame($pro, $this->pricing->getAmountCents($project, 'pro'));
        self::assertSame($band, $this->pricing->getPriceBand($project));
    }

    public static function eventPrices(): iterable
    {
        yield '100 physical' => ['presencial', 100, 24900, 49900, ProjectCommercialPricing::BAND_LOW];
        yield '101 physical' => ['presencial', 101, 49900, 99900, ProjectCommercialPricing::BAND_MID];
        yield '1000 hybrid' => ['hibrido', 1000, 49900, 99900, ProjectCommercialPricing::BAND_MID];
        yield '1001 hybrid' => ['hibrido', 1001, 74900, 149900, ProjectCommercialPricing::BAND_HIGH];
        yield 'virtual ignores online connections and null physical count' => ['virtual', null, 24900, 49900, ProjectCommercialPricing::BAND_LOW];
    }

    #[DataProvider('invalidProjects')]
    public function testPaidOfferRequiresCompletePricingData(Project $project, string $message): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage($message);

        $this->pricing->getAmountCents($project, 'standard');
    }

    public static function invalidProjects(): iterable
    {
        yield 'hybrid null attendees' => [
            (new Project())->setType('evento')->setEventModality('hibrido'),
            'Physical attendee count is required',
        ];
        yield 'onsite null attendees' => [
            (new Project())->setType('evento')->setEventModality('presencial'),
            'Physical attendee count is required',
        ];
        yield 'missing filming type' => [
            (new Project())->setType('rodaje'),
            'A valid filming type is required',
        ];
        yield 'invalid filming type' => [
            (new Project())->setType('rodaje')->setFilmingType('invalid'),
            'A valid filming type is required',
        ];
    }
    public function testReadOnlyTierPriceBandsMatchPerProjectPricing(): void
    {
        $pricing = new ProjectCommercialPricing();

        self::assertSame(['low' => 0, 'mid' => 0, 'high' => 0], $pricing->getTierPricesCents('basic'));
        self::assertSame(['low' => 24900, 'mid' => 49900, 'high' => 74900], $pricing->getTierPricesCents('standard'));
        self::assertSame(['low' => 49900, 'mid' => 99900, 'high' => 149900], $pricing->getTierPricesCents('pro'));
    }

}
