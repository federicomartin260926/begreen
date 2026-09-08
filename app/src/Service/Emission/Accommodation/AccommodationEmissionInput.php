<?php

declare(strict_types=1);

namespace App\Service\Emission\Accommodation;

final readonly class AccommodationEmissionInput
{
    public const TYPE_HOTEL = 'hotel';
    public const TYPE_HOSTEL = 'hostel';
    public const TYPE_APARTMENT = 'apartment';
    public const TYPE_OTHER = 'other';

    public function __construct(
        public ?\DateTimeInterface $startDate,
        public ?\DateTimeInterface $endDate,
        public ?string $iso3,
        public ?string $accommodationType,
        public ?string $stars,
        public ?string $occupiedRooms,
        public ?string $nights,
        public ?string $people,
    ) {
    }

    /** @return list<string> */
    public static function accommodationTypes(): array
    {
        return [self::TYPE_HOTEL, self::TYPE_HOSTEL, self::TYPE_APARTMENT, self::TYPE_OTHER];
    }

    /** @return list<string> */
    public static function hotelStars(): array
    {
        return ['2', '3', '4', '5', 'unknown'];
    }
}
