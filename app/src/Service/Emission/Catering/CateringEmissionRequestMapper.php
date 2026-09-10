<?php

declare(strict_types=1);

namespace App\Service\Emission\Catering;

use App\Service\Emission\EmissionCountryCatalog;
use Symfony\Component\HttpFoundation\Request;

final class CateringEmissionRequestMapper
{
    private readonly EmissionCountryCatalog $countryCatalog;

    public function __construct(?EmissionCountryCatalog $countryCatalog = null)
    {
        $this->countryCatalog = $countryCatalog ?? new EmissionCountryCatalog();
    }

    public function map(Request $request): CateringEmissionInput
    {
        $startDate = $this->date($request, 'startDate');
        $endDate = $this->date($request, 'endDate');
        if ($endDate < $startDate) {
            throw new \InvalidArgumentException('endDate cannot be before startDate.');
        }

        $activityType = $this->requiredString($request, 'activityType');
        if (!in_array($activityType, CateringEmissionInput::activityTypes(), true)) {
            throw new \InvalidArgumentException('activityType is not supported.');
        }

        return new CateringEmissionInput(
            startDate: $startDate,
            endDate: $endDate,
            country: $this->country($request),
            activityType: $activityType,
            people: $this->optionalString($request, 'people'),
            menuLines: CateringEmissionInput::TYPE_MEAL === $activityType ? $this->menuLines($request) : [],
            tablewareType: $this->optionalString($request, 'tablewareType'),
            sandwichType: $this->optionalString($request, 'sandwichType'),
            preparedCount: $this->optionalString($request, 'preparedCountSingle'),
            consumedCount: $this->optionalString($request, 'consumedCountSingle'),
            containerVolumeLiters: $this->optionalString($request, 'containerVolumeLiters'),
            containerMaterial: $this->optionalString($request, 'containerMaterial'),
            containerCount: $this->optionalString($request, 'containerCount'),
            description: $this->optionalString($request, 'description'),
            unitCount: $this->optionalString($request, 'unitCount'),
            litersPerUnit: $this->optionalString($request, 'litersPerUnit'),
            serviceCount: $this->optionalString($request, 'serviceCount'),
            coffeeType: $this->optionalString($request, 'coffeeType'),
            gasType: $this->optionalString($request, 'gasType'),
            cylinderCount: $this->optionalString($request, 'cylinderCount'),
            kgPerCylinder: $this->optionalString($request, 'kgPerCylinder'),
        );
    }

    /** @return list<CateringMenuLine> */
    private function menuLines(Request $request): array
    {
        $data = $request->request->all();
        $variants = $data['menuVariant'] ?? [];
        $prepared = $data['preparedCount'] ?? [];
        $consumed = $data['consumedCount'] ?? [];
        if (!is_array($variants) || !is_array($prepared) || !is_array($consumed)) {
            throw new \InvalidArgumentException('Menu line fields must be arrays.');
        }
        if (array_keys($variants) !== array_keys($prepared) || array_keys($variants) !== array_keys($consumed)) {
            throw new \InvalidArgumentException('Menu line fields must use matching indexes.');
        }

        $lines = [];
        foreach ($variants as $index => $variant) {
            $line = [
                $this->arrayString($variant, 'menuVariant'),
                $this->arrayString($prepared[$index], 'preparedCount'),
                $this->arrayString($consumed[$index], 'consumedCount'),
            ];
            if ([null, null, null] === $line) {
                continue;
            }
            $lines[] = new CateringMenuLine(...$line);
        }

        return $lines;
    }

    private function date(Request $request, string $field): \DateTimeImmutable
    {
        $value = $this->requiredString($request, $field);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) {
            throw new \InvalidArgumentException(sprintf('%s must be a valid YYYY-MM-DD date.', $field));
        }

        return $date;
    }

    private function country(Request $request): string
    {
        return $this->countryCatalog->normalizeIso3($this->requiredString($request, 'country'));
    }

    private function requiredString(Request $request, string $field): string
    {
        $value = $this->optionalString($request, $field);
        if (null === $value) {
            throw new \InvalidArgumentException(sprintf('%s is required.', $field));
        }

        return $value;
    }

    private function optionalString(Request $request, string $field): ?string
    {
        return $this->arrayString($request->request->get($field), $field);
    }

    private function arrayString(mixed $value, string $field): ?string
    {
        if (null === $value || (is_string($value) && '' === trim($value))) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a string.', $field));
        }

        return trim($value);
    }
}
