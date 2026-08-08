<?php

namespace App\Form;

use App\Entity\EmissionActivity;
use App\Entity\Category;
use App\Entity\CategoryGhg;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use App\Repository\EmissionActivityRepository;

class EmissionActivityType extends AbstractType
{
    public function __construct(private EmissionActivityRepository $repo) {}

    private function allSubcategoryCodes(): array
    {
        // Merge de todas las subcategorías fijas definidas en el repo
        $maps = [
            $this->repo->getSubcategories('Energía'),
            $this->repo->getSubcategories('Transporte'),
            $this->repo->getSubcategories('Viajes'),
            $this->repo->getSubcategories('Materiales'),
        ];
        $codes = [];
        foreach ($maps as $m) {
            $codes = array_merge($codes, array_values($m)); // valores = códigos
        }
        $codes = array_values(array_unique($codes));
        // Para ChoiceType: ['carretera'=>'carretera', ...]
        return array_combine($codes, $codes);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var EmissionActivity $activity */
        $activity = $options['data'];
        $source = $activity->getEmissionSource();
        $sourceName = $source?->getName();
        $sourceYear = $source?->getYear();

        $locales            = $options['locales'] ?? ['es','en'];
        $defaultLocale      = $options['default_locale'] ?? 'es';
        $translatableFields = $options['translatable_fields'] ?? ['name','unit','subcategory'];
        $existingTranslations = $options['translations'] ?? [];

        // ===== Campos "fuente" (no traducibles)
        $builder
            ->add('sourceName', ChoiceType::class, [
                'mapped' => false,
                'label' => 'backend.admin.emission.form.source_name',
                'required' => true,
                'choices' => [
                    'MITECO' => 'MITECO',
                    'DEFRA'  => 'DEFRA',
                ],
                'placeholder' => 'backend.admin.emission.form.source_placeholder',
                'data' => $sourceName,
            ])
            ->add('sourceYear', IntegerType::class, [
                'mapped' => false,
                'label' => 'backend.admin.emission.form.source_year',
                'required' => true,
                'data' => $sourceYear ?? (int) date('Y'),
            ]);

        // ===== ES (mapeado a la entidad) =====
        $builder
            ->add('name', TextType::class, [
                'label' => 'backend.admin.emission.form.name',
                'required' => true,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('unit', TextType::class, [
                'label' => 'backend.admin.emission.form.unit',
                'required' => true,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('emissionFactor', NumberType::class, [
                'label' => 'backend.admin.emission.form.emission_factor',
                'required' => true,
                'scale' => 15,
                'attr' => ['class' => 'form-control', 'step' => 'any'],
            ])
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'choice_label' => 'name',
                'query_builder' => fn($r) => $r->createQueryBuilder('c')->orderBy('c.sortOrder', 'ASC')->addOrderBy('c.name', 'ASC'),
                'required' => true,
                'label' => 'backend.admin.emission.form.category',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('subcategory', ChoiceType::class, [
                'label' => 'backend.admin.emission.form.subcategory',
                'choices' => $this->allSubcategoryCodes(), // <- TODAS
                'placeholder' => 'backend.admin.emission.form.subcategory_placeholder',
                'required' => false,
                'attr' => ['class' => 'form-control'],
                'choice_translation_domain' => false, // las etiquetas las pone el JS
            ])
            ->add('categoryGhg', EntityType::class, [
                'class' => CategoryGhg::class,
                'choice_label' => 'name',
                'required' => true,
                'label' => 'backend.admin.emission.form.category_ghg',
                'attr' => ['class' => 'form-select'],
            ]);

        // ===== Campos por-locale (unmapped) para tabs =====
        foreach ($locales as $loc) {
            if ($loc === $defaultLocale) {
                continue; // el idioma por defecto se edita en los campos mapeados
            }

            if (in_array('name', $translatableFields, true)) {
                $builder->add('name_' . $loc, TextType::class, [
                    'label'    => 'backend.admin.emission.form.name',
                    'mapped'   => false,
                    'required' => false,
                    'data'     => $existingTranslations[$loc]['name'] ?? null,
                    'attr'     => ['class' => 'form-control'],
                ]);
            }

            if (in_array('unit', $translatableFields, true)) {
                $builder->add('unit_' . $loc, TextType::class, [
                    'label'    => 'backend.admin.emission.form.unit',
                    'mapped'   => false,
                    'required' => false,
                    'data'     => $existingTranslations[$loc]['unit'] ?? null,
                    'attr'     => ['class' => 'form-control'],
                ]);
            }
        }

        // Para tabs en la vista
        $builder->setAttribute('locales', $locales);
        $builder->setAttribute('default_locale', $defaultLocale);
        $builder->setAttribute('translatable_fields', $translatableFields);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'          => EmissionActivity::class,
            'translation_domain'  => 'messages',
            'locales'             => ['es','en'],
            'default_locale'      => 'es',
            'translatable_fields' => ['name','unit','subcategory'],
            'translations'        => [],
        ]);
    }
}
