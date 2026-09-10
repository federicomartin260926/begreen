<?php

namespace App\Service\Emission\Transport;

final readonly class TransportEmissionResult
{
    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_FACTOR_NOT_AVAILABLE = 'factor_not_available';
    public const STATUS_EXPLICIT_NULL_FACTOR = 'explicit_null_factor';
    public const STATUS_EXTERNAL_FACTOR_REQUIRED = 'external_factor_required';
    public const STATUS_UNSUPPORTED = 'unsupported';
    public const STATUS_DIRECT_OPERATOR_EMISSION = 'direct_operator_emission';
    public const STATUS_DIRECT_ZERO = 'direct_zero';

    /**
     * @param array<string, string>|null $criteria
     */
    public function __construct(
        public string $status,
        public ?string $normalizedActivityValue,
        public ?string $normalizedActivityUnit,
        public ?string $generatedKgCo2e,
        public ?array $criteria,
        public ?string $functionalKey,
        public int $activityYear,
        public ?int $factorYear = null,
        public ?string $factorValue = null,
        public ?string $factorUnit = null,
        public ?string $source = null,
        public ?string $sourceDetail = null,
        public bool $isFallback = false,
        public ?string $fallbackReason = null,
        public ?string $factorId = null,
        public ?int $factorActivityYear = null,
        public string $temporalType = 'ANNUAL',
        public ?string $factorVersion = null,
        public bool $isGeographicProxy = false,
        public ?string $proxyGeography = null,
        public ?string $qualityStatus = null,
        public array $factorMetadata = [],
    ) {
    }
}
