<?php

namespace App\Form;

use App\Entity\ProjectDocument;
use App\Enum\ProjectDocumentCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class ProjectDocumentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $accept = implode(',', array_map(
            static fn (string $extension): string => '.'.$extension,
            ProjectDocumentCatalog::ALLOWED_EXTENSIONS,
        ));

        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'backend.projects.form.documents.type',
                'placeholder' => 'backend.projects.form.documents.type_placeholder',
                'choices' => ProjectDocumentCatalog::typeChoices(true),
                'choice_translation_domain' => 'messages',
                'choice_attr' => static function ($choice, $key, $value): array {
                    return ProjectDocumentCatalog::isAnimationType((string) $value)
                        ? ['data-animation-only' => '1']
                        : [];
                },
            ])
            ->add('kind', HiddenType::class, [
                'constraints' => [
                    new Assert\Choice(choices: [
                        ProjectDocumentCatalog::KIND_FILE,
                        ProjectDocumentCatalog::KIND_LINK,
                    ]),
                ],
            ])
            ->add('otherType', TextType::class, [
                'label' => 'backend.projects.form.documents.other_type',
                'required' => false,
                'attr' => [
                    'maxlength' => 120,
                    'data-project-document-target' => 'otherType',
                ],
            ])
            ->add('file', FileType::class, [
                'label' => 'backend.projects.form.documents.file',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new Assert\File(
                        maxSize: '4M',
                        extensions: ProjectDocumentCatalog::ALLOWED_EXTENSIONS,
                        maxSizeMessage: 'backend.projects.form.documents.validation.file_too_large',
                        extensionsMessage: 'backend.projects.form.documents.validation.file_type_not_allowed',
                    ),
                ],
                'attr' => [
                    'accept' => $accept,
                ],
            ])
            ->add('url', UrlType::class, [
                'label' => 'backend.projects.form.documents.url',
                'required' => false,
                'default_protocol' => 'https',
                'attr' => [
                    'placeholder' => 'https://...',
                ],
            ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $document = $event->getData();
            if (!$document instanceof ProjectDocument || null === $document->getId()) {
                return;
            }

            $event->getForm()->add('type', ChoiceType::class, [
                'label' => 'backend.projects.form.documents.type',
                'placeholder' => 'backend.projects.form.documents.type_placeholder',
                'choices' => ProjectDocumentCatalog::typeChoices(true),
                'choice_translation_domain' => 'messages',
                'mapped' => false,
                'data' => $document->getType(),
                'disabled' => true,
                'choice_attr' => static function ($choice, $key, $value): array {
                    return ProjectDocumentCatalog::isAnimationType((string) $value)
                        ? ['data-animation-only' => '1']
                        : [];
                },
            ]);
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $form = $event->getForm();
            $document = $event->getData();

            if (!$document instanceof ProjectDocument) {
                return;
            }

            if ('other' === $document->getType()) {
                if (null === $document->getOtherType()) {
                    $form->get('otherType')->addError(new FormError(
                        'backend.projects.form.documents.validation.other_type_required'
                    ));
                }
            } else {
                $document->setOtherType(null);
            }

            $file = $form->get('file')->getData();

            if (ProjectDocumentCatalog::KIND_FILE === $document->getKind()) {
                if (null !== $document->getUrl()) {
                    $form->get('url')->addError(new FormError(
                        'backend.projects.form.documents.validation.file_with_url'
                    ));
                }

                if (!$file instanceof UploadedFile && null === $document->getStoredName()) {
                    $form->get('file')->addError(new FormError(
                        'backend.projects.form.documents.validation.file_required'
                    ));
                }

                return;
            }

            if (ProjectDocumentCatalog::KIND_LINK === $document->getKind()) {
                if ($file instanceof UploadedFile) {
                    $form->get('file')->addError(new FormError(
                        'backend.projects.form.documents.validation.link_with_file'
                    ));
                }

                if (null === $document->getUrl()) {
                    $form->get('url')->addError(new FormError(
                        'backend.projects.form.documents.validation.url_required'
                    ));
                }

                if (null !== $document->getStoredName()) {
                    $form->addError(new FormError(
                        'backend.projects.form.documents.validation.kind_change_not_allowed'
                    ));
                }
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProjectDocument::class,
        ]);
    }
}
