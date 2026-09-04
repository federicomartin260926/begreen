<?php

namespace App\Service\Emission;

final class EmissionFactorKeyGenerator
{
    /**
     * Normalizes only JSON structure. Scalar values are kept verbatim so
     * spelling, capitalization and whitespace retain their source semantics.
     *
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    public function normalize(array $criteria): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = $this->normalizeValue($criteria);

        return $normalized;
    }

    /** @param array<string, mixed> $criteria */
    public function generate(array $criteria): string
    {
        return hash('sha256', json_encode(
            $this->normalize($criteria),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (is_scalar($value) || null === $value) {
                return $value;
            }

            throw new \InvalidArgumentException('Emission factor criteria must contain only JSON values.');
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeValue($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeValue($item);
        }

        return $value;
    }
}
