<?php

namespace App\Service\Animation;

use App\Enum\ProjectCatalog;

final class AnimationConfigurationCatalog
{
    public const array TECHNIQUES = [
        'tec_2d_digital' => 'TEC_2D_DIGITAL',
        'tec_2d_tradicional' => 'TEC_2D_TRADICIONAL',
        'tec_3d_cgi' => 'TEC_3D_CGI',
        'tec_anim_directa_pelicula' => 'TEC_ANIM_DIRECTA_PELICULA',
        'tec_arena_polvo' => 'TEC_ARENA_POLVO',
        'tec_brickfilm' => 'TEC_BRICKFILM',
        'tec_mocap' => 'TEC_MOCAP',
        'tec_collage_mixtas' => 'TEC_COLLAGE_MIXTAS',
        'tec_fotogrametria_escaneo3d' => 'TEC_FOTOGRAMETRIA_ESCANEO3D',
        'tec_impresion3d_stopmotion' => 'TEC_IMPRESION3D_STOPMOTION',
        'tec_marionetas_stopmotion' => 'TEC_MARIONETAS_STOPMOTION',
        'tec_pinscreen' => 'TEC_PINSCREEN',
        'tec_pintura_cristal' => 'TEC_PINTURA_CRISTAL',
        'tec_pixilation' => 'TEC_PIXILATION',
        'tec_plastilina_stopmotion' => 'TEC_PLASTILINA_STOPMOTION',
        'tec_realtime' => 'TEC_REALTIME',
        'tec_recortes_siluetas' => 'TEC_RECORTES_SILUETAS',
        'tec_rotoscopia' => 'TEC_ROTOSCOPIA',
        'tec_vfx' => 'TEC_VFX',
        'tec_vr_espacial' => 'TEC_VR_ESPACIAL',
        'tec_virtual_production' => 'TEC_VIRTUAL_PRODUCTION',
    ];

    public const array TECHNIQUE_LABELS = [
        '2D digital' => 'TEC_2D_DIGITAL',
        '2D tradicional' => 'TEC_2D_TRADICIONAL',
        '3D / CGI' => 'TEC_3D_CGI',
        'Animación directa sobre película' => 'TEC_ANIM_DIRECTA_PELICULA',
        'Arena y polvo' => 'TEC_ARENA_POLVO',
        'Brickfilm' => 'TEC_BRICKFILM',
        'Captura de movimiento' => 'TEC_MOCAP',
        'Collage y técnicas mixtas' => 'TEC_COLLAGE_MIXTAS',
        'Fotogrametría / Escaneado 3D' => 'TEC_FOTOGRAMETRIA_ESCANEO3D',
        'Impresión 3D en stop-motion' => 'TEC_IMPRESION3D_STOPMOTION',
        'Marionetas (stop-motion)' => 'TEC_MARIONETAS_STOPMOTION',
        'Pinscreen' => 'TEC_PINSCREEN',
        'Pintura sobre cristal' => 'TEC_PINTURA_CRISTAL',
        'Pixilation' => 'TEC_PIXILATION',
        'Plastilina (stop-motion)' => 'TEC_PLASTILINA_STOPMOTION',
        'Real-Time / Motor en tiempo real' => 'TEC_REALTIME',
        'Recortes y siluetas' => 'TEC_RECORTES_SILUETAS',
        'Rotoscopia' => 'TEC_ROTOSCOPIA',
        'VFX / Efectos visuales' => 'TEC_VFX',
        'VR / animación espacial' => 'TEC_VR_ESPACIAL',
        'Virtual Production' => 'TEC_VIRTUAL_PRODUCTION',
    ];

    public const array SHOOTING_FORCED_TECHNIQUES = [
        'TEC_ANIM_DIRECTA_PELICULA',
        'TEC_ARENA_POLVO',
        'TEC_BRICKFILM',
        'TEC_MOCAP',
        'TEC_FOTOGRAMETRIA_ESCANEO3D',
        'TEC_IMPRESION3D_STOPMOTION',
        'TEC_MARIONETAS_STOPMOTION',
        'TEC_PINSCREEN',
        'TEC_PINTURA_CRISTAL',
        'TEC_PIXILATION',
        'TEC_PLASTILINA_STOPMOTION',
        'TEC_RECORTES_SILUETAS',
        'TEC_VIRTUAL_PRODUCTION',
    ];

    public const array STRUCTURES = [
        'micro' => 'ESC_MICRO',
        'studio_space' => 'ESC_ESTUDIO_ESPACIO',
        'technical_infrastructure' => 'ESC_INFRA_TECNICA',
        'mixed' => 'ESC_MIXTA',
    ];

    public const array STRUCTURE_UI_LABELS = [
        'micro' => 'Profesional individual / microestudio',
        'studio_space' => 'Estudio con espacio propio o gestionado',
        'technical_infrastructure' => 'Estudio con infraestructura técnica propia',
        'mixed' => 'Estructura mixta / varias sedes / proveedores externos',
    ];

    public const array STRUCTURE_LABELS = [
        'Profesional individual / microestudio' => 'ESC_MICRO',
        'Estudio con espacio propio/gestionado' => 'ESC_ESTUDIO_ESPACIO',
        'Estudio con infraestructura técnica propia' => 'ESC_INFRA_TECNICA',
        'Estructura mixta / varias sedes / proveedores externos' => 'ESC_MIXTA',
    ];

    public const array PROCESSING_LEVELS = [
        'basic' => 'PROC_NIVEL_BASICO',
        'medium' => 'PROC_NIVEL_MEDIO',
        'intensive' => 'PROC_NIVEL_INTENSIVO',
    ];

    public const array PROCESSING_LEVEL_UI_LABELS = [
        'basic' => 'Edición, 2D o postproducción sencilla',
        'medium' => 'Render, composición o procesamiento frecuente',
        'intensive' => 'Render complejo, simulaciones o grandes cargas de procesamiento',
    ];

    public const array PROCESSING_LEVEL_LABELS = [
        'Básico' => 'PROC_NIVEL_BASICO',
        'Medio' => 'PROC_NIVEL_MEDIO',
        'Intensivo' => 'PROC_NIVEL_INTENSIVO',
    ];

    public const array PROCESSING_INFRASTRUCTURES = [
        'workstations' => 'PROC_INFRA_EQUIPOS',
        'servers' => 'PROC_INFRA_SERVIDORES',
        'cloud' => 'PROC_INFRA_CLOUD',
    ];

    public const array PROCESSING_INFRASTRUCTURE_UI_LABELS = [
        'workstations' => 'En los ordenadores de trabajo del equipo',
        'servers' => 'En servidores o una render farm gestionados por la producción',
        'cloud' => 'En cloud o mediante un proveedor externo',
    ];

    public const array PROCESSING_INFRASTRUCTURE_LABELS = [
        'Equipos de trabajo' => 'PROC_INFRA_EQUIPOS',
        'Servidores / render farm gestionados' => 'PROC_INFRA_SERVIDORES',
        'Cloud / proveedor externo' => 'PROC_INFRA_CLOUD',
    ];

    public const array PLANS = [
        'basic' => 'PLAN_BASIC_POR_SCORE',
        'standard' => 'PLAN_STANDARD_POR_SCORE',
        'pro' => 'PLAN_PRO_POR_SCORE',
    ];

    public const array AI = [
        'animation.ai.yes' => 'IA_SI',
        'animation.ai.no' => 'IA_NO',
    ];

    public const array SHOOTING = [
        'animation.shooting.yes' => 'RODAJE_SI',
        'animation.shooting.no' => 'RODAJE_NO',
    ];

    public const string INTERACTIVE_DISTRIBUTION_UI_KEY = 'distribution.interactive';
    public const string INTERACTIVE_DISTRIBUTION = ProjectCatalog::INTERACTIVE_DISTRIBUTION_MEDIUM;
    public const array INTERACTIVE = [
        true => 'INTERACTIVO_SI',
        false => 'INTERACTIVO_NO',
    ];

    public static function techniqueForcesShooting(string $code): bool
    {
        return in_array($code, self::SHOOTING_FORCED_TECHNIQUES, true);
    }

    private function __construct()
    {
    }
}
