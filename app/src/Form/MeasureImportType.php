<?php
// src/Form/MeasureImportType.php
namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class MeasureImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => false,                     // el modal ya pone el título
                'mapped' => false,
                'required' => true,
                'attr' => [
                    'accept' => '.xlsx,.xls',        // UX: limitar a Excel en el selector
                ],
                'constraints' => [
                    new File([
                        'maxSize' => '5M',
                        'mimeTypes' => [
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
                            'application/vnd.ms-excel',                                           // .xls
                            'application/octet-stream',                                           // algunos navegadores
                            'application/zip',                                                    // algunos servidores reportan .xlsx como zip
                        ],
                        // Clave traducible (dominio validators)
                        'mimeTypesMessage' => 'backend.measures.import.mime_invalid',
                    ]),
                ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
