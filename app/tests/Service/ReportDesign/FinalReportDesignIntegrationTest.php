<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportDesign;

use App\Service\ReportDesign\ReportDesignCatalog;
use App\Service\ReportDesign\ReportDesignComponentCatalog;
use App\Service\ReportDesign\ReportDesignDataCatalog;
use App\Service\ReportDesign\ReportDesignManager;
use App\Service\ReportDesign\ReportDesignRenderer;
use App\Service\ReportDesign\ReportDesignStorage;
use App\Service\ReportDesign\ReportDesignValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\IdentityTranslator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class FinalReportDesignIntegrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/final-design-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        @rmdir($this->directory);
    }

    public function testFinalApprovedDesignResolvesWithoutChangingPlanContract(): void
    {
        $catalog = new ReportDesignCatalog();
        $components = new ReportDesignComponentCatalog();
        $data = new ReportDesignDataCatalog();
        $validator = new ReportDesignValidator($components, $data);
        $storage = new ReportDesignStorage($this->directory);
        $manager = new ReportDesignManager($storage, $validator);
        $final = $catalog->finalSustainabilityReportGeneral();
        $finalHtml = implode("\n", array_map(static fn (string $key): string => '[COMPONENT:'.$key.']', $components->requiredKeys($final)));
        $manager->saveDraft($final, $finalHtml, '.report-design-final-cover { color: #176b43; }');
        $manager->approve($final);

        $templates = [];
        $templateDirectory = dirname(__DIR__, 3).'/templates/';
        foreach ($components->all($final) as $key => $component) {
            $templates[$component['template']] = in_array($key, ['final-featured', 'final-gallery'], true)
                ? file_get_contents($templateDirectory.$component['template'])
                : '<section>'.$key.'</section>';
        }
        $twig = new Environment(new ArrayLoader($templates));
        $twig->addExtension(new TranslationExtension(new IdentityTranslator()));
        $renderer = new ReportDesignRenderer($storage, $validator, $components, $data, $twig);
        $rendered = $renderer->renderApproved($final, [
            'report' => [],
            'texts' => [],
            'featuredMeasures' => [[
                'plan' => ['measure' => ['name' => 'Medida aprobada']],
                'implementation' => ['state' => 'implemented'],
                'photo' => [
                    'dataUri' => 'data:image/png;base64,ZmVhdHVyZWQ=',
                    'caption' => 'Foto usada',
                    'author' => 'Equipo',
                ],
                'text' => 'Relato aprobado',
                'formattedText' => \App\Service\FinalReport\FinalReportRichText::render('Relato aprobado'),
            ]],
            'photos' => [
                [
                    'dataUri' => 'data:image/png;base64,ZmVhdHVyZWQ=',
                    'caption' => 'Foto usada',
                    'author' => 'Equipo',
                    'isCover' => false,
                    'usedInFeatured' => true,
                ],
                [
                    'dataUri' => 'data:image/png;base64,Z2FsbGVyeQ==',
                    'caption' => 'Foto complementaria',
                    'author' => null,
                    'isCover' => false,
                    'usedInFeatured' => false,
                ],
            ],
        ]);

        self::assertNotNull($rendered);
        self::assertStringContainsString('final-cover', $rendered['html']);
        self::assertStringContainsString('pdf-featured-photo', $rendered['html']);
        self::assertStringContainsString('Relato aprobado', $rendered['html']);
        self::assertSame(1, substr_count($rendered['html'], 'Foto usada'));
        self::assertSame(0, substr_count($rendered['html'], 'Foto complementaria'));
        self::assertStringNotContainsString('report-design-final-gallery', $rendered['html']);
        self::assertStringContainsString('pdf-featured-image-cell', $rendered['html']);
        self::assertStringContainsString('pdf-featured-story-cell', $rendered['html']);
        self::assertNull($storage->loadApproved($catalog->sustainabilityPlanGeneral()));
        self::assertSame(['cover', 'analytics', 'ai_report', 'departments', 'final_conclusion'], $components->requiredKeys($catalog->sustainabilityPlanGeneral()));
    }
}
