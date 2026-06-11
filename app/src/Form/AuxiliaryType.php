<?php
// src/Form/AuxiliaryType.php
namespace App\Form;

use App\Entity\Department;
use App\Entity\MeasureBlock;
use App\Entity\Protocol;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AuxiliaryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $type                 = $options['auxiliary_type'] ?? null;
        $locales              = $options['locales'] ?? ['es','en'];
        $defaultLocale        = $options['default_locale'] ?? 'es';
        $translatableFields   = $options['translatable_fields'] ?? [];   // p.ej. ['name','description']
        $existingTranslations = $options['translations'] ?? [];          // array devuelto por findTranslations()

        // ===== Campos base comunes =====
        $builder->add('name', TextType::class, [
            'label' => 'backend.aux.form.name',
            'attr'  => ['class' => 'form-control'],
        ]);

        if (in_array($type, ['category', 'department'], true)) {
            $builder->add('sortOrder', \Symfony\Component\Form\Extension\Core\Type\IntegerType::class, [
                'label' => 'backend.aux.form.sort_order',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['class' => 'form-control', 'min' => 0],
            ]);
        }

        // ===== Específicos por tipo =====
        if ($type === 'protocol') {
            // Tipo de protocolo (rodaje, evento, ambos)
            $builder->add('type', ChoiceType::class, [
                'label'   => 'backend.aux.form.project_type',
                'choices' => [
                    'backend.aux.project_type.filming' => Protocol::TYPE_RODAJE,
                    'backend.aux.project_type.event'   => Protocol::TYPE_EVENTO,
                    'backend.aux.project_type.both'    => Protocol::TYPE_AMBOS,
                ],
                'placeholder'               => 'backend.common.select_type',
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'form-select'],
            ]);

            // Agrupar medidas por (categoría o departamento)
            $builder->add('groupingBy', ChoiceType::class, [
                'label'   => 'backend.protocol.form.grouping_by',
                'choices' => [
                    'backend.protocol.form.grouping.category'   => Protocol::GROUP_BY_CATEGORY,
                    'backend.protocol.form.grouping.department' => Protocol::GROUP_BY_DEPARTMENT,
                ],
                'placeholder'               => false,
                'required'                  => true,
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'form-select'],
            ]);
        }

        if ($type === 'measure_block') {
            $builder
                ->add('protocol', EntityType::class, [
                    'label' => 'backend.aux.form.protocol',
                    'class' => Protocol::class,
                    'choice_label' => 'name',
                    'required' => true,
                    'placeholder' => 'backend.common.select',
                    'attr' => ['class' => 'form-select'],
                ])
                ->add('code', TextType::class, [
                    'label' => 'backend.aux.form.code',
                    'attr'  => ['class' => 'form-control'],
                ])
                ->add('active', ChoiceType::class, [
                    'label' => 'backend.aux.form.active',
                    'choices' => [
                        'backend.common.yes' => true,
                        'backend.common.no' => false,
                    ],
                    'placeholder' => false,
                    'choice_translation_domain' => 'messages',
                    'attr' => ['class' => 'form-select'],
                ])
                ->add('hasScreeningQuestion', ChoiceType::class, [
                    'label' => 'backend.aux.form.has_screening_question',
                    'choices' => [
                        'backend.common.yes' => true,
                        'backend.common.no' => false,
                    ],
                    'placeholder' => false,
                    'choice_translation_domain' => 'messages',
                    'attr' => ['class' => 'form-select'],
                ])
                ->add('screeningQuestion', TextareaType::class, [
                    'label' => 'backend.aux.form.screening_question',
                    'required' => false,
                    'attr' => ['class' => 'form-control', 'rows' => 3],
                ]);
        }

        if ($type === 'department') {
            $builder->add('projectType', ChoiceType::class, [
                'label'    => 'backend.aux.form.project_type',
                'required' => false,
                'choices'  => [
                    'backend.aux.project_type.generic' => null,
                    'backend.aux.project_type.filming' => 'rodaje',
                    'backend.aux.project_type.event'   => 'evento',
                ],
                'placeholder'               => 'backend.aux.project_type.generic',
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'form-select'],
            ]);
        }

        if ($type === 'position') {
            $builder->add('department', EntityType::class, [
                'label'        => 'backend.aux.form.department',
                'class'        => Department::class,
                'choice_label' => 'name',
                'required'     => false,
                'placeholder'  => 'backend.aux.form.no_department',
                'attr'         => ['class' => 'form-select'],
            ]);
        }

        if ($type === 'esg' || $type === 'category_ghg') {
            $builder->add('description', TextareaType::class, [
                'label'    => 'backend.aux.form.description',
                'required' => false,
                'attr'     => ['class' => 'form-control', 'rows' => 4],
            ]);
        }

        if ($type === 'ods') {
            $builder
                ->add('code', TextType::class, [
                    'label' => 'backend.aux.form.code',
                    'attr'  => ['class' => 'form-control'],
                ])
                ->add('description', TextareaType::class, [
                    'label'    => 'backend.aux.form.description',
                    'required' => false,
                    'attr'     => ['class' => 'form-control', 'rows' => 4],
                ]);
        }

        // ===== Campos de traducción (unmapped) para locales != default =====
        foreach ($locales as $loc) {
            if ($loc === $defaultLocale) continue;

            foreach ($translatableFields as $field) {
                $fieldName = $field . '_' . $loc; // p.ej. name_en
                $labelBase = 'backend.aux.form.' . $field;
                $label     = sprintf('%s (%s)', $labelBase, strtoupper($loc));
                $initial   = $existingTranslations[$loc][$field] ?? null;

                $builder->add($fieldName, $field === 'description' ? TextareaType::class : TextType::class, [
                    'label'    => $label,
                    'required' => false,
                    'mapped'   => false,
                    'data'     => $initial,
                    'attr'     => $field === 'description'
                        ? ['class' => 'form-control', 'rows' => 4]
                        : ['class' => 'form-control'],
                ]);
            }
        }

        // Para la vista (tabs, etc.)
        $builder->setAttribute('locales', $locales);
        $builder->setAttribute('default_locale', $defaultLocale);
        $builder->setAttribute('translatable_fields', $translatableFields);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'          => null,
            'auxiliary_type'      => null,
            'locales'             => ['es','en'],
            'default_locale'      => 'es',
            'translatable_fields' => ['name'], // por defecto solo name
            'translations'        => [],       // array devuelto por findTranslations()
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'auxiliary';
    }
}
