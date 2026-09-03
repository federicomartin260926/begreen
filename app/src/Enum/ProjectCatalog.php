<?php

namespace App\Enum;

final class ProjectCatalog
{
    public const FILMING_GENRE_ANIMATION = 'animacion';

    public const FILMING_TYPES = [
        'feature',
        'short',
        'tv_series',
        'tv_program',
        'live_broadcast',
        'advert',
        'corporate_video',
        'music_video',
        'online_content',
        'shooting',
    ];

    public const DISTRIBUTION_MEDIA = [
        'cinema',
        'tv',
        'streaming',
        'internet',
        'social_media',
        'photography',
        'radio',
    ];

    public const ECO_MANAGER_STATUSES = [
        'designated',
        'pending',
        'not_required',
    ];

    public const PROJECT_COMPANY_TYPES = [
        'production_company',
        'agency',
        'client',
    ];

    public const PROJECT_FUNDING_SOURCE_TYPES = [
        'production_company',
        'agency',
        'client',
        'sponsor',
        'grant',
        'other',
    ];

    public static function distributionMediaChoices(): array
    {
        return self::choices('backend.projects.form.distribution_media.options.', self::DISTRIBUTION_MEDIA);
    }

    public static function filmingTypeChoices(): array
    {
        return self::choices('backend.projects.form.filming_type.options.', self::FILMING_TYPES);
    }

    public static function ecoManagerStatusChoices(): array
    {
        return self::choices('backend.projects.form.eco_manager_status.options.', self::ECO_MANAGER_STATUSES);
    }

    public static function projectCompanyTypeChoices(): array
    {
        return self::choices('backend.projects.form.project_company_type.options.', self::PROJECT_COMPANY_TYPES);
    }

    public static function projectFundingSourceTypeChoices(): array
    {
        return self::choices('backend.projects.form.project_funding_source_type.options.', self::PROJECT_FUNDING_SOURCE_TYPES);
    }

    public static function isDistributionMedia(string $value): bool
    {
        return in_array($value, self::DISTRIBUTION_MEDIA, true);
    }

    public static function isFilmingType(string $value): bool
    {
        return in_array($value, self::FILMING_TYPES, true);
    }

    public static function isEcoManagerStatus(string $value): bool
    {
        return in_array($value, self::ECO_MANAGER_STATUSES, true);
    }

    public static function isProjectCompanyType(string $value): bool
    {
        return in_array($value, self::PROJECT_COMPANY_TYPES, true);
    }

    public static function isProjectFundingSourceType(string $value): bool
    {
        return in_array($value, self::PROJECT_FUNDING_SOURCE_TYPES, true);
    }

    private static function choices(string $prefix, array $values): array
    {
        $choices = [];

        foreach ($values as $value) {
            $choices[$prefix . $value] = $value;
        }

        return $choices;
    }
}
