<?php

namespace App\Tests\Service;

use App\Service\GamificationMessageSelector;
use App\Service\SustainabilityGamificationMessageCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class SustainabilityGamificationMessageCatalogTest extends TestCase
{
    public function testProgressSelectionExcludesPreviousKeyAndTranslatesSelectedMessage(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource(
            'yaml',
            dirname(__DIR__, 2) . '/translations/gamification.es.yaml',
            'es',
            'gamification'
        );

        $selector = $this->createMock(GamificationMessageSelector::class);
        $selector->expects(self::once())
            ->method('select')
            ->willReturnCallback(static function (array $keys): string {
                self::assertCount(9, $keys);
                self::assertNotContains('progress.seed.001', $keys);

                return 'progress.seed.002';
            });

        $catalog = new SustainabilityGamificationMessageCatalog($translator, $translator, $selector);
        $message = $catalog->choose('progress', 'seed', 'progress.seed.001');

        self::assertSame('progress.seed.002', $message['key']);
        self::assertSame('progress', $message['type']);
        self::assertSame(
            'Cada decisión que tomáis aquí llega al set convertida en algo real.',
            $message['text']
        );
    }
}
