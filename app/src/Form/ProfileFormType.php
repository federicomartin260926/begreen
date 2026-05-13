<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'form.name',
                'attr' => ['placeholder' => 'form.name']
            ])
            ->add('surnames', TextType::class, [
                'label' => 'form.surnames',
                'attr' => ['placeholder' => 'form.surnames']
            ])
            ->add('phone', TextType::class, [
                'label' => 'form.phone',
                'required' => false,
                'attr' => ['placeholder' => 'form.phone']
            ])
            ->add('submit_profile', SubmitType::class, [
                'label' => 'form.save_profile',
                'attr' => ['class' => 'btn btn-primary btn-begreen']
            ]);
    }
}
