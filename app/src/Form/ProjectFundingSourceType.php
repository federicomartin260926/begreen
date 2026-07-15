<?php

namespace App\Form;

use App\Entity\ProjectFundingSource;
use App\Enum\ProjectCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProjectFundingSourceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'backend.projects.form.project_funding_source.type',
                'choices' => ProjectCatalog::projectFundingSourceTypeChoices(),
                'choice_translation_domain' => 'messages',
                'required' => true,
                'placeholder' => false,
            ])
            ->add('name', TextType::class, [
                'label' => 'backend.projects.form.project_funding_source.name',
                'required' => true,
            ])
            ->add('percentage', NumberType::class, [
                'label' => 'backend.projects.form.project_funding_source.percentage',
                'required' => true,
                'html5' => true,
                'scale' => 2,
                'attr' => [
                    'min' => 0,
                    'max' => 100,
                    'step' => '0.01',
                    'data-project-collection-target' => 'percentage',
                    'data-action' => 'input->project-collection#updateFundingSummary',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProjectFundingSource::class,
        ]);
    }
}
