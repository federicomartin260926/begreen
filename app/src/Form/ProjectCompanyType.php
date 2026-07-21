<?php

namespace App\Form;

use App\Entity\ProjectCompany;
use App\Enum\ProjectCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProjectCompanyType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

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
            ])
            ->add('logoFile', FileType::class, [
                'label' => 'backend.projects.form.project_company.logo',
                'mapped' => false,
                'required' => false,
                'help' => 'backend.projects.form.project_company.logo_help',
                'constraints' => [
                    new File(
                        maxSize: '2M',
                        extensions: ['png', 'jpg', 'jpeg', 'webp'],
                        maxSizeMessage: 'backend.project_company.logo.max_size',
                        extensionsMessage: 'backend.project_company.logo.invalid_format',
                    ),
                ],
                'attr' => [
                    'accept' => '.png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp',
                    'data-action' => 'change->project-collection#validateLogoFile',
                    'data-logo-max-size' => 2 * 1024 * 1024,
                    'data-logo-size-error' => $this->translator->trans('backend.projects.form.project_company.logo_size_error'),
                    'data-logo-format-error' => $this->translator->trans('backend.projects.form.project_company.logo_format_error'),
                ],
            ])
            ->add('removeLogo', CheckboxType::class, [
                'label' => 'backend.projects.form.project_company.remove_logo',
                'mapped' => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProjectCompany::class,
        ]);
    }
}
