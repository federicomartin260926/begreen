<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;

class ImportExcelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FileType::class, [
            'label' => 'backend.admin.emission.import.file_label',
            'translation_domain' => 'messages',
            'mapped' => false,
            'required' => true,
            'help' => 'begreen_upload_help_excel_5m',
            'attr' => [
                'accept' => '.xls,.xlsx',
                'data-upload-max-size' => 5 * 1024 * 1024,
                'data-upload-extensions' => 'xls,xlsx',
            ],
            'constraints' => [
                new File([
                    'mimeTypes' => [
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                    ],
                    'mimeTypesMessage' => 'backend.admin.emission.import.invalid_mime',
                    'maxSize' => '5M',
                    'maxSizeMessage' => 'backend.admin.emission.import.max_size',
                ]),
            ],
        ]);
    }
}
