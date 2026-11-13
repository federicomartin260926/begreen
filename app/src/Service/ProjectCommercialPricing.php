<?php

namespace App\Service;

use App\Entity\Project;
use App\Enum\ProjectCatalog;

final class ProjectCommercialPricing
{
    public const BAND_LOW = 'low';
    public const BAND_MID = 'mid';
    public const BAND_HIGH = 'high';

    private const PRICES = [
        self::BAND_LOW => [
            Project::COMMERCIAL_TIER_STANDARD => 24900,
            Project::COMMERCIAL_TIER_PRO => 49900,
        ],
        self::BAND_MID => [
            Project::COMMERCIAL_TIER_STANDARD => 49900,
            Project::COMMERCIAL_TIER_PRO => 99900,
        ],
        self::BAND_HIGH => [
            Project::COMMERCIAL_TIER_STANDARD => 74900,
            Project::COMMERCIAL_TIER_PRO => 149900,
        ],
    ];

    /**
     * Display-only price bands for the single project tier (amounts in cents).
     * Checkout continues to use getAmountCents() for each actual project.
     *
     * @return array<string, int>
     */
    public function getTierPricesCents(string $tier): array
    {
        $tier = strtolower(trim($tier));
        if ($tier === Project::COMMERCIAL_TIER_BASIC) {
            return [self::BAND_LOW => 0, self::BAND_MID => 0, self::BAND_HIGH => 0];
        }
        if (!in_array($tier, [Project::COMMERCIAL_TIER_STANDARD, Project::COMMERCIAL_TIER_PRO], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported commercial tier "%s".', $tier));
        }

        return array_map(static fn (array $prices): int => $prices[$tier], self::PRICES);
    }

    public function getAmountCents(Project $project, string $tier): int
    {
        $tier = strtolower(trim($tier));
        if ($tier === Project::COMMERCIAL_TIER_BASIC) {
            return 0;
        }
        if (!in_array($tier, [Project::COMMERCIAL_TIER_STANDARD, Project::COMMERCIAL_TIER_PRO], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported commercial tier "%s".', $tier));
        }

        return self::PRICES[$this->getPriceBand($project)][$tier];
    }

    public function getUpgradeAmountCents(Project $project, string $fromTier, string $toTier): int
    {
        $fromTier = strtolower(trim($fromTier));
        $toTier = strtolower(trim($toTier));
        if ($fromTier !== Project::COMMERCIAL_TIER_STANDARD || $toTier !== Project::COMMERCIAL_TIER_PRO) {
            throw new \InvalidArgumentException('Only the Standard to Pro upgrade is currently priced.');
        }

        return $this->getAmountCents($project, $toTier) - $this->getAmountCents($project, $fromTier);
    }

    /**
     * Fingerprint of all project fields used for commercial pricing.
     * Recheck this when a Stripe payment is confirmed to reject stale quotes.
     */
    public function getPricingFingerprint(Project $project): string
    {
        $this->getPriceBand($project); // Reject incomplete paid-project data.

        return hash('sha256', json_encode([
            'type' => $project->getType(),
            'filming_type' => $project->getFilmingType(),
            'event_modality' => $project->getEventModality(),
            'physical_attendees' => $project->getEventAttendeesCount(),
        ], JSON_THROW_ON_ERROR));
    }

    public function getPriceBand(Project $project): string
    {
        return match ($project->getType()) {
            'rodaje' => $this->resolveFilmingBand($project),
            'evento' => $this->resolveEventBand($project),
            default => throw new \DomainException('Project type is required to calculate commercial pricing.'),
        };
    }

    private function resolveFilmingBand(Project $project): string
    {
        $filmingType = $project->getFilmingType();
        if (!is_string($filmingType) || !in_array($filmingType, ProjectCatalog::FILMING_TYPES, true)) {
            throw new \DomainException('A valid filming type is required to calculate commercial pricing.');
        }

        return match ($filmingType) {
            'feature' => self::BAND_MID,
            'tv_series' => self::BAND_HIGH,
            default => self::BAND_LOW,
        };
    }

    private function resolveEventBand(Project $project): string
    {
        $modality = $project->getEventModality();
        if ($modality === 'virtual') {
            return self::BAND_LOW;
        }
        if (!in_array($modality, ['presencial', 'hibrido'], true)) {
            throw new \DomainException('A valid event modality is required to calculate commercial pricing.');
        }

        $attendees = $project->getEventAttendeesCount();
        if ($attendees === null) {
            throw new \DomainException('Physical attendee count is required to calculate commercial pricing.');
        }
        if ($attendees < 0) {
            throw new \DomainException('Physical attendee count cannot be negative.');
        }

        return match (true) {
            $attendees <= 100 => self::BAND_LOW,
            $attendees <= 1000 => self::BAND_MID,
            default => self::BAND_HIGH,
        };
    }
}
