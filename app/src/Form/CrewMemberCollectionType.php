<?php

namespace App\Form;

use App\Entity\Project;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CrewMemberCollectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Project $project */
        $project = $builder->getData();
        $projectType = $project?->getType(); // 'rodaje' | 'evento'

        $builder->add('crewMembers', CollectionType::class, [
            'entry_type'    => CrewMemberType::class,
            'entry_options' => [
                'label'       => false,
                'projectType' => $projectType,
            ],
            'allow_add'      => true,
            'allow_delete'   => true,
            'by_reference'   => false,
            'label'          => false,
            'prototype'      => true,
            'prototype_name' => '__crew__',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // data será Project
        ]);
    }
}
