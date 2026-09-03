<?php

namespace App\Form;

use App\Entity\CrewDepartment;
use App\Entity\CrewMember;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CrewMemberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'backend.projects.crew.form.name',
                'attr' => ['placeholder' => 'backend.projects.crew.form.name_ph'],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'backend.projects.crew.form.last_name',
                'required' => false,
                'attr' => ['placeholder' => 'backend.projects.crew.form.last_name_ph'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'backend.projects.crew.form.email',
                'required' => false,
                'attr' => ['placeholder' => 'backend.projects.crew.form.email_ph'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'backend.projects.crew.form.phone',
                'required' => false,
                'attr' => ['placeholder' => 'backend.projects.crew.form.phone_ph'],
            ])
            ->add('assignments', CollectionType::class, [
                'entry_type' => CrewMemberAssignmentType::class,
                'entry_options' => [
                    'label' => false,
                    'crew_scope' => $options['crew_scope'],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
                'prototype' => true,
                'prototype_name' => '__assignment__',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CrewMember::class,
        ]);
        $resolver->setRequired('crew_scope');
        $resolver->setAllowedTypes('crew_scope', 'string');
        $resolver->setAllowedValues('crew_scope', CrewDepartment::SCOPES);
    }
}
