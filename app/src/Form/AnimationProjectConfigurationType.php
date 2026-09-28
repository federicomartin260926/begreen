<?php

namespace App\Form;

use App\Service\Animation\AnimationConfigurationCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AnimationProjectConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $animationRule = 'type:rodaje&filmingGenre:animacion';

        $builder
            ->add('techniques', ChoiceType::class, [
                'label' => 'backend.projects.form.animation.techniques',
                'choices' => AnimationConfigurationCatalog::TECHNIQUE_LABELS,
                'choice_translation_domain' => false,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choice_attr' => static fn (mixed $choice): array => [
                    'data-project-target' => 'animationTechnique',
                    'data-action' => 'change->project#change',
                    'data-force-shooting' => AnimationConfigurationCatalog::techniqueForcesShooting((string) $choice) ? '1' : '0',
                    'data-required-group-when' => $animationRule,
                ],
            ])
            ->add('shootingAnswered', ChoiceType::class, [
                'label' => 'backend.projects.form.animation.shooting',
                'choices' => [
                    'backend.common.yes' => true,
                    'backend.common.no' => false,
                ],
                'choice_translation_domain' => 'messages',
                'multiple' => false,
                'expanded' => true,
                'placeholder' => false,
                'required' => false,
                'choice_attr' => static fn (): array => [
                    'data-project-target' => 'animationShootingChoice',
                    'data-required-when' => $animationRule,
                ],
            ])
            ->add('structure', ChoiceType::class, [
                'label' => 'backend.projects.form.animation.structure',
                'choices' => array_combine(
                    array_values(AnimationConfigurationCatalog::STRUCTURE_UI_LABELS),
                    array_values(AnimationConfigurationCatalog::STRUCTURES),
                ),
                'choice_translation_domain' => false,
                'multiple' => false,
                'expanded' => true,
                'placeholder' => false,
                'required' => false,
                'choice_attr' => static fn (): array => ['data-required-when' => $animationRule],
            ])
            ->add('processingLevel', ChoiceType::class, [
                'label' => 'backend.projects.form.animation.processing_level',
                'choices' => array_combine(
                    array_values(AnimationConfigurationCatalog::PROCESSING_LEVEL_UI_LABELS),
                    array_values(AnimationConfigurationCatalog::PROCESSING_LEVELS),
                ),
                'choice_translation_domain' => false,
                'multiple' => false,
                'expanded' => true,
                'placeholder' => false,
                'required' => false,
                'choice_attr' => static fn (): array => ['data-required-when' => $animationRule],
            ])
            ->add('processingInfrastructures', ChoiceType::class, [
                'label' => 'backend.projects.form.animation.processing_infrastructures',
                'choices' => array_combine(
                    array_values(AnimationConfigurationCatalog::PROCESSING_INFRASTRUCTURE_UI_LABELS),
                    array_values(AnimationConfigurationCatalog::PROCESSING_INFRASTRUCTURES),
                ),
                'choice_translation_domain' => false,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choice_attr' => static fn (): array => ['data-required-group-when' => $animationRule],
            ])
            ->add('usesAi', ChoiceType::class, [
                'label' => 'backend.projects.form.animation.uses_ai',
                'choices' => [
                    'backend.common.yes' => true,
                    'backend.common.no' => false,
                ],
                'choice_translation_domain' => 'messages',
                'multiple' => false,
                'expanded' => true,
                'placeholder' => false,
                'required' => false,
                'choice_attr' => static fn (): array => ['data-required-when' => $animationRule],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'empty_data' => [],
        ]);
    }
}
