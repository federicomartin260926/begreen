<?php
// src/Form/MeasureType.php
namespace App\Form;

use App\Entity\Measure;
use App\Entity\Protocol;
use App\Entity\Category;
use App\Entity\Department;
use App\Repository\DepartmentRepository;
use App\Entity\Ods;
use App\Entity\EsG;
use App\Entity\Scope;
use App\Entity\CategoryGhg;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MeasureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $projectType          = $options['projectType'] ?? null;
        $locales              = $options['locales'] ?? ['es','en'];
        $defaultLocale        = $options['default_locale'] ?? 'es';
        $translatableFields   = $options['translatable_fields'] ?? ['name','description'];
        $existingTranslations = $options['translations'] ?? [];

        // ===== Campos translatables base (locale por defecto, mapeados) =====
        $builder
            ->add('name', TextType::class, [
                'label' => 'backend.measures.form.name',
            ])
            ->add('nameReview', TextType::class, [
                'label'    => 'backend.measures.form.name_review',
                'required' => false,
                'attr'     => ['placeholder' => 'backend.measures.form.name_review_ph'],
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'backend.measures.form.description',
                'required' => false,
                'attr'     => ['rows' => 4],
            ]);

        // ===== Campos por-locale (unmapped) para tabs =====
        foreach ($locales as $loc) {
            if ($loc === $defaultLocale) {
                continue; // el idioma por defecto se edita en los campos mapeados
            }

            // name_{loc}
            if (in_array('name', $translatableFields, true)) {
                $builder->add('name_' . $loc, TextType::class, [
                    'label'    => 'backend.measures.form.name',
                    'mapped'   => false,
                    'required' => false,
                    'data'     => $existingTranslations[$loc]['name'] ?? null,
                    'attr'     => ['class' => 'form-control'],
                ]);
            }

            // nameReview_{loc}
            if (in_array('nameReview', $translatableFields, true)) {
                $builder->add('nameReview_' . $loc, TextType::class, [
                    'label'    => 'backend.measures.form.name_review',
                    'mapped'   => false,
                    'required' => false,
                    'data'     => $existingTranslations[$loc]['nameReview'] ?? null,
                    'attr'     => [
                        'class'       => 'form-control',
                        'placeholder' => 'backend.measures.form.name_review_ph',
                    ],
                ]);
            }

            // description_{loc}
            if (in_array('description', $translatableFields, true)) {
                $builder->add('description_' . $loc, TextareaType::class, [
                    'label'    => 'backend.measures.form.description',
                    'mapped'   => false,
                    'required' => false,
                    'data'     => $existingTranslations[$loc]['description'] ?? null,
                    'attr'     => ['class' => 'form-control', 'rows' => 4],
                ]);
            }

            // implementation_{loc}
            if (in_array('implementation', $translatableFields, true)) {
                $builder->add('implementation_' . $loc, TextareaType::class, [
                    'label'    => 'backend.measures.form.implementation',
                    'mapped'   => false,
                    'required' => false,
                    'data'     => $existingTranslations[$loc]['implementation'] ?? null,
                    'attr'     => ['class' => 'form-control', 'rows' => 4],
                ]);
            }

            // verificationSources_{loc}
            if (in_array('verificationSources', $translatableFields, true)) {
                $builder->add('verificationSources_' . $loc, TextType::class, [
                    'label'    => 'backend.measures.form.verification_sources',
                    'mapped'   => false,
                    'required' => false,
                    'data'     => $existingTranslations[$loc]['verificationSources'] ?? null,
                    'attr'     => [
                        'class'       => 'form-control',
                        'maxlength'   => 300,
                        'placeholder' => 'backend.measures.form.verification_sources_ph',
                    ],
                ]);
            }
        }

        // ===== Resto de campos =====
        $builder
            ->add('protocol', EntityType::class, [
                'class' => Protocol::class,
                'choice_label' => 'name',
                'placeholder' => 'backend.common.select',
                'label' => 'backend.measures.form.protocol',
                'required' => false,
            ])
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'choice_label' => 'name',
                'placeholder' => 'backend.common.select',
                'label' => 'backend.measures.form.category',
                'required' => false,
            ])
            ->add('department', EntityType::class, [
                'class' => Department::class,
                'choice_label' => 'name',
                'query_builder' => fn(DepartmentRepository $r) => $r->qbForProjectType($projectType),
                'label' => 'backend.measures.form.department',
                'required' => false,
                'placeholder' => 'backend.common.select',
            ])
            ->add('ods', EntityType::class, [
                'class' => Ods::class,
                'choice_label' => 'name',
                'placeholder' => 'backend.common.select',
                'label' => 'backend.measures.form.ods',
                'required' => false,
            ])
            ->add('esg', EntityType::class, [
                'class' => EsG::class,
                'choice_label' => 'name',
                'placeholder' => 'backend.common.select',
                'label' => 'backend.measures.form.esg',
                'required' => false,
            ])
            ->add('scope', EntityType::class, [
                'class' => Scope::class,
                'choice_label' => 'name',
                'placeholder' => 'backend.common.select',
                'label' => 'backend.measures.form.scope',
                'required' => false,
            ])
            ->add('categoryGhg', EntityType::class, [
                'class' => CategoryGhg::class,
                'choice_label' => 'name',
                'placeholder' => 'backend.common.select',
                'label' => 'backend.measures.form.category_ghg',
                'required' => false,
            ])
            ->add('implementation', TextareaType::class, [
                'label' => 'backend.measures.form.implementation',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('verificationSources', TextType::class, [
                'label' => 'backend.measures.form.verification_sources',
                'required' => false,
                'attr' => [
                    'maxlength' => 300,
                    'placeholder' => 'backend.measures.form.verification_sources_ph'
                ],
            ])
            ->add('mandatory', CheckboxType::class, [
                'label'    => 'backend.measures.form.mandatory',
                'required' => false,
                'help'     => 'backend.measures.form.mandatory_help',
            ])
            ->add('score', IntegerType::class, [
                'label'    => 'backend.measures.form.score',
                'required' => false,
                'attr'     => ['min' => 0, 'max' => 100, 'step' => 1, 'placeholder' => '0–100'],
                'help'     => 'backend.measures.form.score_help',
            ]);

        // Para tabs en la vista
        $builder->setAttribute('locales', $locales);
        $builder->setAttribute('default_locale', $defaultLocale);
        $builder->setAttribute('translatable_fields', $translatableFields);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'          => Measure::class,
            'projectType'         => null,       // 'rodaje' | 'evento' | null
            'locales'             => ['es','en'],
            'default_locale'      => 'es',
            // Añadimos nameReview y (si quieres) implementation + verificationSources para tabs i18n
            'translatable_fields' => ['name','nameReview','description','implementation','verificationSources'],
            'translations'        => [],
        ]);
    }
}
