<?php

namespace App\Service\Animation;

use InvalidArgumentException;

final readonly class AnimationConfiguration
{
    /** @var list<string> */
    private array $techniques;

    /** @var list<string> */
    private array $processingInfrastructures;

    /** @var list<string>|null */
    private ?array $distribution;

    /**
     * All codes use the internal values exposed by AnimationConfigurationCatalog.
     * An empty selection or null remains unanswered until selection is requested.
     *
     * @param list<string>      $techniques
     * @param list<string>      $processingInfrastructures
     * @param list<string>|null $distribution
     */
    public function __construct(
        array $techniques,
        private ?string $structure,
        private ?bool $shootingAnswered,
        array $processingInfrastructures,
        private ?string $processingLevel,
        private ?bool $usesAi,
        ?array $distribution,
        private ?string $plan,
    ) {
        $this->techniques = $this->normalizeKnownCodes(
            $techniques,
            array_values(AnimationConfigurationCatalog::TECHNIQUES),
            'técnica',
        );
        $this->processingInfrastructures = $this->normalizeKnownCodes(
            $processingInfrastructures,
            array_values(AnimationConfigurationCatalog::PROCESSING_INFRASTRUCTURES),
            'infraestructura de procesamiento',
        );
        $this->assertNullableKnownCode($structure, array_values(AnimationConfigurationCatalog::STRUCTURES), 'estructura');
        $this->assertNullableKnownCode($processingLevel, array_values(AnimationConfigurationCatalog::PROCESSING_LEVELS), 'nivel de procesamiento');
        $this->assertNullableKnownCode($plan, array_keys(AnimationConfigurationCatalog::PLANS), 'plan');
        $this->distribution = null === $distribution ? null : $this->normalizeStrings($distribution, 'distribución');
    }

    /** @return list<string> */
    public function techniques(): array
    {
        return $this->techniques;
    }

    public function structure(): ?string
    {
        return $this->structure;
    }

    public function shootingAnswered(): ?bool
    {
        return $this->shootingAnswered;
    }

    public function shootingIsForced(): bool
    {
        return [] !== array_intersect($this->techniques, AnimationConfigurationCatalog::SHOOTING_FORCED_TECHNIQUES);
    }

    public function effectiveShooting(): ?bool
    {
        return $this->shootingIsForced() ? true : $this->shootingAnswered;
    }

    /** @return list<string> */
    public function processingInfrastructures(): array
    {
        return $this->processingInfrastructures;
    }

    public function processingLevel(): ?string
    {
        return $this->processingLevel;
    }

    public function usesAi(): ?bool
    {
        return $this->usesAi;
    }

    public function isInteractive(): ?bool
    {
        if (null === $this->distribution) {
            return null;
        }

        return in_array(AnimationConfigurationCatalog::INTERACTIVE_DISTRIBUTION, $this->distribution, true);
    }

    public function plan(): ?string
    {
        return $this->plan;
    }

    public function assertComplete(bool $requirePlan = true): void
    {
        $missing = [];
        if ([] === $this->techniques) {
            $missing[] = 'técnicas';
        }
        if (null === $this->structure) {
            $missing[] = 'estructura';
        }
        if (!$this->shootingIsForced() && null === $this->shootingAnswered) {
            $missing[] = 'rodaje';
        }
        if ([] === $this->processingInfrastructures) {
            $missing[] = 'infraestructura de procesamiento';
        }
        if (null === $this->processingLevel) {
            $missing[] = 'nivel de procesamiento';
        }
        if (null === $this->usesAi) {
            $missing[] = 'IA';
        }
        if (null === $this->distribution) {
            $missing[] = 'distribución';
        }
        if ($requirePlan && null === $this->plan) {
            $missing[] = 'plan';
        }

        if ([] !== $missing) {
            throw new InvalidArgumentException(sprintf('Configuración Animation incompleta: %s.', implode(', ', $missing)));
        }
    }

    /**
     * @param list<mixed>  $codes
     * @param list<string> $knownCodes
     *
     * @return list<string>
     */
    private function normalizeKnownCodes(array $codes, array $knownCodes, string $field): array
    {
        $normalized = $this->normalizeStrings($codes, $field);
        foreach ($normalized as $code) {
            if (!in_array($code, $knownCodes, true)) {
                throw new InvalidArgumentException(sprintf('Código desconocido de %s: "%s".', $field, $code));
            }
        }

        return array_values(array_filter(
            $knownCodes,
            static fn (string $knownCode): bool => in_array($knownCode, $normalized, true),
        ));
    }

    /** @param list<mixed> $values @return list<string> */
    private function normalizeStrings(array $values, string $field): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value) || '' === trim($value)) {
                throw new InvalidArgumentException(sprintf('Valor inválido en %s.', $field));
            }
            $normalized[] = trim($value);
        }

        return array_values(array_unique($normalized));
    }

    /** @param list<string> $knownCodes */
    private function assertNullableKnownCode(?string $code, array $knownCodes, string $field): void
    {
        if (null !== $code && !in_array($code, $knownCodes, true)) {
            throw new InvalidArgumentException(sprintf('Código desconocido de %s: "%s".', $field, $code));
        }
    }
}
