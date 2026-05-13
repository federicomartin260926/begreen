<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;

class ResetPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', PasswordType::class, [
            'label' => 'reset_password.password_label',
            'translation_domain' => 'messages',
            'constraints' => [
                new NotBlank(['message' => 'Por favor ingresa una contraseña']),
                new Length([
                    'min' => 6,
                    'minMessage' => 'Tu contraseña debe tener al menos {{ limit }} caracteres',
                ]),
            ],
        ]);
    }
}
