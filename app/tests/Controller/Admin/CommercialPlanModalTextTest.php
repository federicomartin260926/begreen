<?php

namespace App\Tests\Controller\Admin;

use PHPUnit\Framework\TestCase;

final class CommercialPlanModalTextTest extends TestCase
{
    public function testFeatureInfoModalTextsReflectTheFunctionalModel(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../templates/admin/commercial_plan/form.html.twig');
        $es = file_get_contents(__DIR__ . '/../../../translations/messages.es.yaml');
        $en = file_get_contents(__DIR__ . '/../../../translations/messages.en.yaml');

        self::assertNotFalse($template);
        self::assertNotFalse($es);
        self::assertNotFalse($en);

        self::assertSame(3, substr_count($template, 'backend.commercial_plans.form.coming_soon'));

        self::assertStringContainsString(
            'Permite al administrador o verificador externo revisar las medidas, comprobar sus acciones y evidencias, registrar el resultado de la auditoría y validar su cumplimiento de cara a la certificación.',
            $es
        );
        self::assertStringContainsString(
            'Permite crear medidas propias del proyecto, visibles únicamente dentro de su Plan de Sostenibilidad.',
            $es
        );
        self::assertStringContainsString(
            'Añade una vista/resumen del estado de validación: medidas pendientes de auditar, verificadas, con correcciones solicitadas y nivel global de cumplimiento.',
            $es
        );
        self::assertStringContainsString(
            'Permite personalizar documentos y presentaciones con la identidad visual del cliente o proyecto, como logotipo, marca y otros elementos gráficos.',
            $es
        );

        self::assertStringContainsString(
            'Allows an administrator or external verifier to review measures, check their actions and evidence, record the audit result, and validate compliance for certification.',
            $en
        );
        self::assertStringContainsString(
            'Allows project-specific measures to be created and shown only within its Sustainability Plan.',
            $en
        );
        self::assertStringContainsString(
            'Adds a validation status view/summary: measures awaiting audit, verified measures, requested corrections, and the overall compliance level.',
            $en
        );
        self::assertStringContainsString(
            'Allows documents and presentations to be customized with the client or project\'s visual identity, such as logo, brand and other graphic elements.',
            $en
        );
    }
}
