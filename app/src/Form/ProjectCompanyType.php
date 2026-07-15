<?php

namespace App\Form;

use App\Entity\ProjectCompany;
use App\Enum\ProjectCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProjectCompanyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'backend.projects.form.project_company.type',
                'choices' => ProjectCatalog::projectCompanyTypeChoices(),
                'choice_translation_domain' => 'messages',
                'required' => true,
                'placeholder' => false,
            ])
            ->add('name', TextType::class, [
                'label' => 'backend.projects.form.project_company.name',
                'required' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProjectCompany::class,
        ]);
    }
}
