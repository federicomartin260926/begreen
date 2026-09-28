<?php

namespace App\Enum;

final class ProjectDocumentCatalog
{
    public const KIND_FILE = 'file';
    public const KIND_LINK = 'link';

    public const COMMON_TYPES = [
        'script',
        'treatment',
        'briefing',
        'storyboard',
        'budget',
        'report_dossier',
        'ppm_dossier',
        'shooting_plan',
        'production_plan',
        'schedule',
        'production_breakdown',
        'equipment_list',
        'locations',
        'suppliers',
        'delivery_specs',
        'client_platform_requirements',
        'other',
    ];

    public const ANIMATION_TYPES = [
        'animatic',
        'series_project_bible',
        'concept_visual_design',
        'software_hardware_list',
        'pipeline_docs',
        'asset_list',
        'shot_list',
        'render_plan',
        'capture_mocap_plan',
        'materials_list',
    ];

    public const ALLOWED_EXTENSIONS = [
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'ppt',
        'pptx',
        'txt',
        'rtf',
        'csv',
        'jpg',
        'jpeg',
        'png',
        'webp',
        'zip',
    ];

    public static function allTypeCodes(): array
    {
        return [...self::COMMON_TYPES, ...self::ANIMATION_TYPES];
    }

    public static function isKnownType(string $type): bool
    {
        return in_array($type, self::allTypeCodes(), true);
    }

    public static function isAnimationType(string $type): bool
    {
        return in_array($type, self::ANIMATION_TYPES, true);
    }

    public static function typeChoices(bool $includeAnimation): array
    {
        $codes = $includeAnimation
            ? self::allTypeCodes()
            : self::COMMON_TYPES;

        $choices = [];
        foreach ($codes as $code) {
            $choices['backend.projects.form.documents.types.'.$code] = $code;
        }

        return $choices;
    }
}
