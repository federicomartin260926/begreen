<?php
namespace App\Form;

use App\Entity\CommunicationFile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File as FileConstraint;
use Symfony\Component\Form\FormEvents;

class CommunicationFileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $isEdit = $options['is_edit'] ?? false;

        $constraints = [];

        if (!$isEdit) {
            // En creación siempre validar archivo
            $constraints[] = new FileConstraint([
                'maxSize' => '5M',
                'mimeTypes' => [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ],
                'mimeTypesMessage' => 'backend.admin.communication.form.mime_invalid',
            ]);
        } else {
            // En edición, solo validar si se sube un archivo nuevo
            $builder->addEventListener(FormEvents::POST_SUBMIT, function ($event) {
                $form = $event->getForm();
                $file = $form->get('file')->getData();

                if ($file) {
                    $form->get('file')->addConstraint(new FileConstraint([
                        'maxSize' => '5M',
                        'mimeTypes' => [
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ],
                        'mimeTypesMessage' => 'backend.admin.communication.form.mime_invalid',
                    ]));
                }
            });
        }

        $builder
            ->add('file', FileType::class, [
                'label' => 'backend.admin.communication.form.file_label',
                'mapped' => false,
                'required' => !$isEdit,
                'constraints' => $constraints,
                // 'translation_domain' => 'messages', // opcional (por defecto ya es "messages")
            ])
            ->add('description', TextareaType::class, [
                'label' => 'backend.admin.communication.form.description_label',
                'required' => false,
                // 'translation_domain' => 'messages',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => CommunicationFile::class,
            'is_edit' => false,
        ]);
    }
}
