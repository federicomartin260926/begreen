<?php

namespace App\Form;

use App\Entity\CrewDepartment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CrewDepartmentType extends AbstractType
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
            ->add('scope', ChoiceType::class, [
                'label' => 'backend.crew_catalog.form.scope',
                'choices' => [
                    'backend.crew_catalog.scope.rodaje' => CrewDepartment::SCOPE_FILMING,
                    'backend.crew_catalog.scope.evento' => CrewDepartment::SCOPE_EVENT,
                    'backend.crew_catalog.scope.animacion' => CrewDepartment::SCOPE_ANIMATION,
                ],
                'choice_translation_domain' => 'messages',
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
            'data_class' => CrewDepartment::class,
            'translations' => [],
        ]);
        $resolver->setAllowedTypes('translations', 'array');
    }
}
