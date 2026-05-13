<?php

namespace App\Form;

use App\Entity\EmissionSource;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EmissionSourceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre de la fuente',
                'required' => true,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ej: MITECO, DEFRA, IPCC...']
            ])
            ->add('year', IntegerType::class, [
                'label' => 'Año de validez',
                'required' => true,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ej: 2023']
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmissionSource::class,
        ]);
    }
}
