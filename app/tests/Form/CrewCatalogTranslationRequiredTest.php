<?php

namespace App\Tests\Form;

use App\Entity\CrewDepartment;
use App\Entity\CrewPosition;
use App\Form\CrewDepartmentType;
use App\Form\CrewPositionType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Constraints\NotBlank;

final class CrewCatalogTranslationRequiredTest extends KernelTestCase
{
    public function testDepartmentEnglishNameIsRequired(): void
    {
        self::bootKernel();
        $department = (new CrewDepartment())->setScope(CrewDepartment::SCOPE_FILMING);
        $form = self::getContainer()->get('form.factory')->create(CrewDepartmentType::class, $department, [
            'csrf_protection' => false,
        ]);
        $this->assertEnglishNameRequired($form->get('name_en')->getConfig());
    }

    public function testPositionEnglishNameIsRequired(): void
    {
        self::bootKernel();
        $form = self::getContainer()->get('form.factory')->create(CrewPositionType::class, new CrewPosition(), [
            'csrf_protection' => false,
        ]);
        $this->assertEnglishNameRequired($form->get('name_en')->getConfig());
    }

    private function assertEnglishNameRequired(\Symfony\Component\Form\FormConfigInterface $config): void
    {
        self::assertTrue($config->getRequired());
        self::assertFalse($config->getMapped());
        $constraints = $config->getOption('constraints');
        self::assertCount(1, $constraints);
        self::assertInstanceOf(NotBlank::class, $constraints[0]);
        $validator = self::getContainer()->get('validator');
        self::assertGreaterThan(0, $validator->validate('', $constraints)->count());
        self::assertCount(0, $validator->validate('Valid English name', $constraints));
    }
}
