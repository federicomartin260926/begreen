<?php

namespace App\Form;

use App\Entity\EmissionRecord;
use App\Repository\EmissionActivityRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

class EnergyEmissionType extends AbstractType
{
    public function __construct(
        private readonly EmissionActivityRepository $activityRepository,
        private readonly TranslatorInterface $translator
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var EmissionRecord|null $record */
        $record = $options['data'] ?? null;

        // Fuente por defecto; si hay proyecto, usar la suya
        $emissionSourceName = $record?->getProject()?->getEmissionSourceName() ?: 'MITECO';

        // Actividades del último año para mapear subcat -> unidad
        $activities   = $this->activityRepository->getActivitiesForLatestYear($emissionSourceName, 'Energía');
        $unitBySubcat = [];
        foreach ($activities as $act) {
            $sub = (string) $act->getSubcategory();
            if ($sub !== '' && !isset($unitBySubcat[$sub])) {
                $unitBySubcat[$sub] = (string) $act->getUnit();
            }
        }

        // Subcategorías de Energía (CÓDIGOS canónicos) desde BBDD
        // Si prefieres la lista fija, podrías usar el array canónico directamente.
        $codes = $this->activityRepository->getSubcategoriesByCategoryName('Energía'); // ['electricidad','remoto',...]

        // Construir choices traducidos: "Etiqueta traducida" => "codigo"
        $choices = [];
        foreach ($codes as $code) {
            if ($code === null || $code === '') continue;
            $label = $this->translator->trans('backend.emission.subcat.' . $code, [], 'messages');
            $choices[$label] = (string) $code;
        }
        ksort($choices, SORT_NATURAL | SORT_FLAG_CASE);

        // Valor inicial para edición (si ya existe actividad con subcategoría)
        $initialSubcat = $record?->getActivity()?->getSubcategory() ?: null;

        $builder
            ->add('registeredAt', DateType::class, [
                'label' => 'backend.emission.form.registered_at',
                'translation_domain' => 'messages',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => true,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('subCategory', ChoiceType::class, [
                'label' => 'backend.emission.energy.subcategory',
                'translation_domain' => 'messages',
                'choices' => $choices,              // etiquetas ya traducidas
                'choice_translation_domain' => false, // evitar doble traducción
                'placeholder' => 'backend.common.select',
                'required' => true,
                'mapped' => false,                  // el controller resuelve la actividad por subcat
                'data' => $initialSubcat,           // preselección en edición
                'attr' => [
                    'class' => 'form-select',
                    'data-energy-form-target' => 'subCategory',
                    'data-action' => 'change->energy-form#updateUnitLabel',
                ],
                // Pasamos la unidad en data-attrs por opción
                'choice_attr' => function (string $value/*code*/, string $label) use ($unitBySubcat) {
                    $unit = $unitBySubcat[$value] ?? '';
                    return $unit ? ['data-unit' => $unit] : [];
                },
            ])
            ->add('electricityMethod', ChoiceType::class, [
                'label' => 'backend.emission.energy.electricity_method',
                'translation_domain' => 'messages',
                'choice_translation_domain' => 'messages',
                'mapped' => false,
                'required' => false,
                'placeholder' => 'backend.common.select',
                'attr' => [
                    'class' => 'form-control',
                    'data-energy-form-target' => 'electricityMethod',
                ],
                'choices' => [
                    'backend.emission.energy.method.generador'          => 'generador',
                    'backend.emission.energy.method.vehiculo'           => 'vehiculo',
                    'backend.emission.energy.method.gaffer'             => 'gaffer',
                    'backend.emission.energy.method.estimacion_espacio' => 'estimacion_espacio',
                    'backend.emission.energy.method.factura'            => 'factura',
                    'backend.emission.energy.method.contador'           => 'contador',
                    'backend.emission.energy.method.medidor'            => 'medidor',
                ],
            ])
            ->add('amount', NumberType::class, [
                'label' => 'backend.emission.form.amount',
                'translation_domain' => 'messages',
                'scale' => 2,
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'step' => 'any',
                    // tu JS puede leer data-unit de la opción seleccionada
                ],
            ])
            ->add('calculationDetails', HiddenType::class, [
                'mapped' => true,   // lo escribes con JS y se guarda en EmissionRecord
                'attr' => [
                    'data-energy-form-target' => 'detailsField',
                ],
                'empty_data' => '',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmissionRecord::class,
        ]);
    }
}
