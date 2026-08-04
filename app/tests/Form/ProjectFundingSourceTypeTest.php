<?php

namespace App\Tests\Form;

use App\Entity\ProjectFundingSource;
use App\Form\ProjectFundingSourceType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormFactoryInterface;

final class ProjectFundingSourceTypeTest extends KernelTestCase
{
    public function testNewSourceOnlyOffersCompanyTypesAndSubmitsCompanyNameAsHiddenData(): void
    {
        $form = $this->createForm(new ProjectFundingSource());

        self::assertSame(
            ['production_company', 'agency', 'client'],
            array_values($form->get('type')->getConfig()->getOption('choices'))
        );
        self::assertInstanceOf(HiddenType::class, $form->get('name')->getConfig()->getType()->getInnerType());

        $form->submit([
            'type' => 'agency',
            'name' => 'Agencia Norte',
            'percentage' => '100',
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertSame('Agencia Norte', $form->getData()->getName());
    }

    public function testExistingNonCompanyTypeRemainsAvailableForCorrection(): void
    {
        $source = (new ProjectFundingSource())
            ->setType('sponsor')
            ->setName('Patrocinador histórico')
            ->setPercentage('100');
        $form = $this->createForm($source);

        self::assertContains('sponsor', array_values($form->get('type')->getConfig()->getOption('choices')));
    }

    private function createForm(ProjectFundingSource $source): \Symfony\Component\Form\FormInterface
    {
        self::bootKernel();
        $factory = self::getContainer()->get(FormFactoryInterface::class);

        return $factory->create(ProjectFundingSourceType::class, $source);
    }
}
