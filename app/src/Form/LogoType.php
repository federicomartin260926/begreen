<?php

namespace App\Form;

use App\Entity\Logo;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type as T;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class LogoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', T\TextType::class, [
                'label' => 'Nombre (interno)',
                'required' => true,
            ])
            ->add('url', T\UrlType::class, [
                'label' => 'Enlace (opcional)',
                'required' => false,
            ])
            ->add('imageFile', T\FileType::class, [
                'label' => 'Imagen (PNG/JPG/SVG)',
                'mapped' => false,
                'required' => $options['is_edit'] ? false : true,
                'constraints' => [
                    new Assert\File(maxSize: '3M'),
                ],
                'help' => 'Se guardará en /uploads/logos',
            ])
            ->add('sortOrder', T\IntegerType::class, [
                'label' => 'Orden',
                'empty_data' => '0',
            ])
            ->add('isActive', T\CheckboxType::class, [
                'label' => 'Activo',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Logo::class,
            'is_edit' => false,
        ]);
    }
}
