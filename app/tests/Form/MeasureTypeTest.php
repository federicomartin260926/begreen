<?php

namespace App\Tests\Form;

use App\Entity\Measure;
use App\Form\MeasureType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class MeasureTypeTest extends KernelTestCase
{
    public function testAdminFormExposesOptionalGamificationMessageInSpanishAndEnglish(): void
    {
        self::bootKernel();

        /** @var FormFactoryInterface $formFactory */
        $formFactory = self::getContainer()->get('form.factory');
        $form = $formFactory->create(MeasureType::class, new Measure(), [
            'locales' => ['es', 'en'],
            'default_locale' => 'es',
        ]);

        self::assertTrue($form->has('gamificationMessage'));
        self::assertTrue($form->has('gamificationMessage_en'));
        self::assertFalse($form->get('gamificationMessage')->getConfig()->getRequired());
        self::assertFalse($form->get('gamificationMessage_en')->getConfig()->getRequired());
        self::assertFalse($form->get('gamificationMessage_en')->getConfig()->getMapped());
    }
}
