<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use App\Service\Emission\Transport\TransportUiCatalog;

final readonly class BgosCrewMobilityValidator
{
    public function __construct(
        private TransportUiCatalog $transportCatalog,
    ) {
    }

    public function normalize(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    public function assertSupported(
        ?string $mode,
        ?string $vehicleType,
        ?string $fuel,
        ?string $thermalFuel,
    ): void {
        $mode = $this->normalize($mode);
        $vehicleType = $this->normalize($vehicleType);
        $fuel = $this->normalize($fuel);
        $thermalFuel = $this->normalize($thermalFuel);

        $categories = $this->transportCatalog->categories();
        $peopleModes = array_values(array_unique(array_merge(
            $categories['local'] ?? [],
            $categories['travel'] ?? [],
        )));

        if (null !== $mode && !in_array($mode, $peopleModes, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported BGoS crew transport mode "%s".',
                $mode,
            ));
        }

        $config = $this->transportCatalog->configuration();

        if (
            null !== $vehicleType
            && !in_array($vehicleType, $config['vehicleTypes'] ?? [], true)
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported BGoS crew vehicle type "%s".',
                $vehicleType,
            ));
        }

        $allowedFuels = [];
        foreach ($config['fuelsByMode'] ?? [] as $fuels) {
            foreach ($fuels as $allowedFuel) {
                $allowedFuels[$allowedFuel] = true;
            }
        }
        foreach ($config['thermalFuels'] ?? [] as $allowedFuel) {
            $allowedFuels[$allowedFuel] = true;
        }

        if (null !== $fuel && !isset($allowedFuels[$fuel])) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported BGoS crew fuel "%s".',
                $fuel,
            ));
        }

        if (
            null !== $thermalFuel
            && !in_array($thermalFuel, $config['thermalFuels'] ?? [], true)
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported BGoS crew thermal fuel "%s".',
                $thermalFuel,
            ));
        }
    }
}
