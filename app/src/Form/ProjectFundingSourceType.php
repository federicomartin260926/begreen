<?php

namespace App\Form;

use App\Entity\ProjectFundingSource;
use App\Enum\ProjectCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProjectFundingSourceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $typeChoices = ProjectCatalog::projectCompanyTypeChoices();
        $source = $options['data'] ?? null;
        if ($source instanceof ProjectFundingSource && !ProjectCatalog::isProjectCompanyType($source->getType())) {
            foreach (ProjectCatalog::projectFundingSourceTypeChoices() as $label => $value) {
                if ($value === $source->getType()) {
                    $typeChoices[$label] = $value;
                    break;
                }
            }
        }

        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'backend.projects.form.project_funding_source.type',
                'choices' => $typeChoices,
                'choice_translation_domain' => 'messages',
                'required' => true,
                'placeholder' => false,
                'attr' => ['data-funding-type-select' => '1'],
            ])
            ->add('name', HiddenType::class, [
                'label' => false,
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
