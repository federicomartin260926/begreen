<?php

namespace App\Form;

use App\Entity\CrewDepartment;
use App\Entity\CrewPosition;
use App\Repository\CrewDepartmentRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CrewPositionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $translations = $options['translations'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'backend.crew_catalog.form.name_es',
            ])
            ->add('name_en', TextType::class, [
                'label' => 'backend.crew_catalog.form.name_en',
                'mapped' => false,
                'required' => false,
                'data' => $translations['en']['name'] ?? null,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'backend.crew_catalog.form.description_es',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('description_en', TextareaType::class, [
                'label' => 'backend.crew_catalog.form.description_en',
                'mapped' => false,
                'required' => false,
                'data' => $translations['en']['description'] ?? null,
                'attr' => ['rows' => 4],
            ])
            ->add('crewDepartment', EntityType::class, [
                'label' => 'backend.crew_catalog.form.department',
                'class' => CrewDepartment::class,
                'query_builder' => static fn (CrewDepartmentRepository $repository) => $repository
                    ->createQueryBuilder('d')
                    ->orderBy('d.scope', 'ASC')
                    ->addOrderBy('d.sortOrder', 'ASC')
                    ->addOrderBy('d.name', 'ASC'),
                'choice_label' => static fn (CrewDepartment $department): string => sprintf(
                    '%s — %s',
                    strtoupper((string) $department->getScope()),
                    (string) $department->getName()
                ),
                'placeholder' => 'backend.crew_catalog.form.select_department',
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'backend.crew_catalog.form.sort_order',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['min' => 0, 'step' => 1],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CrewPosition::class,
            'translations' => [],
        ]);
        $resolver->setAllowedTypes('translations', 'array');
    }
}
