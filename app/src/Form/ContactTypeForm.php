<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use EWZ\Bundle\RecaptchaBundle\Form\Type\EWZRecaptchaType;
use EWZ\Bundle\RecaptchaBundle\Validator\Constraints\IsTrue as RecaptchaTrue;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

class ContactTypeForm extends AbstractType
{
    private TranslatorInterface $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }
    
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'form.name',
                'attr' => [
                    'placeholder' => 'form.name'
                ]
            ])
            ->add('company', TextType::class, [
                'label' => 'form.company',
                'attr' => [
                    'placeholder' => 'form.company'
                ]
            ])
            ->add('department', TextType::class, [
                'label' => 'form.department',
                'attr' => [
                    'placeholder' => 'form.department'
                ]
            ])
            ->add('email', EmailType::class, [
                'label' => 'form.email',
                'attr' => [
                    'placeholder' => 'form.email'
                ]
            ])
            ->add('phone', TelType::class, [
                'label' => 'form.phone',
                'required' => false,
                'attr' => [
                    'placeholder' => 'form.phone',
                ]
            ])
            ->add('message', TextareaType::class, [
                'label' => 'form.message',
                'attr' => [
                    'placeholder' => 'form.message',
                    'rows' => 5
                ]
            ])
            ->add('captcha', EWZRecaptchaType::class, [
                'label' => false,
                'mapped' => false,
                'constraints' => [
                    new RecaptchaTrue([
                        'message' => 'form.captcha_message',
                    ])
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'form.send_message',
                'attr' => ['class' => 'btn btn-success btn-begreen mt-3']
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // importante para usar traducciones
            'translation_domain' => 'forms',
        ]);
    }
}
