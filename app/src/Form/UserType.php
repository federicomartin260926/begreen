<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{CheckboxType, ChoiceType, EmailType, PasswordType, TelType, TextType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'backend.admin.users.form.name',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('surnames', TextType::class, [
                'label' => 'backend.admin.users.form.surnames',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'backend.admin.users.form.email',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'backend.admin.users.form.phone',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'backend.admin.users.form.roles',
                'choices' => [
                    'backend.roles.ROLE_SUPER_ADMIN'        => 'ROLE_SUPER_ADMIN',
                    'backend.roles.ROLE_ADMIN'              => 'ROLE_ADMIN',
                    'backend.roles.ROLE_MANAGER'            => 'ROLE_MANAGER',
                    'backend.roles.ROLE_USER'               => 'ROLE_USER',
                    'backend.roles.ROLE_ALLOWED_TO_SWITCH'  => 'ROLE_ALLOWED_TO_SWITCH',
                ],
                'multiple' => true,
                'expanded' => true,
                'choice_translation_domain' => 'messages',
                'constraints' => [
                    new NotBlank([
                        'message' => 'backend.admin.users.form.roles_required',
                    ]),
                ],
            ])
            ->add('isVerified', CheckboxType::class, [
                'label' => 'backend.admin.users.form.verified',
                'required' => false,
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'backend.admin.users.form.password',
                'mapped' => false,
                'required' => $options['edit'] ? false : true,
                'attr' => ['autocomplete' => 'new-password', 'class' => 'form-control'],
                'constraints' => $options['edit'] ? [] : [
                    new Assert\NotBlank(['message' => 'backend.admin.users.form.password_required']),
                    new Assert\Length([
                        'min' => 6,
                        'minMessage' => 'backend.admin.users.form.password_min',
                        'max' => 4096,
                    ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'edit' => false,
        ]);
    }
}
