<?php

namespace App\Form;

use App\Entity\ProjectPhaseDate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProjectPhaseDateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Se controla desde la vista/JS; no mostramos etiqueta
            ->add('phase', HiddenType::class)
            ->add('startDate', DateType::class, [
                'widget'   => 'single_text',
                'label'    => 'backend.projects.form.start_date',
                'required' => false,
            ])
            ->add('endDate', DateType::class, [
                'widget'   => 'single_text',
                'label'    => 'backend.projects.form.end_date',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProjectPhaseDate::class,
        ]);
    }
}
