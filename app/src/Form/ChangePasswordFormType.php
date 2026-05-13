<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options'  => ['label' => 'form.new_password'],
                'second_options' => ['label' => 'form.repeat_password'],
                'invalid_message' => 'form.passwords_do_not_match',
            ])
            ->add('submit_password', SubmitType::class, [
                'label' => 'form.change_password',
                'attr' => ['class' => 'btn btn-dark btn-begreen']
            ]);
    }
}
