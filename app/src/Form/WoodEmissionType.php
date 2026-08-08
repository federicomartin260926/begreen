<?php

namespace App\Form;

use App\Entity\EmissionRecord;
use App\Service\Emission\WoodCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Positive;

final class WoodEmissionType extends AbstractType
{
    public function __construct(private readonly WoodCatalog $catalog)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $inputs = $options['wood_details']['inputs'] ?? [];
        $activities = $options['material_activities'];
        $activityChoices = [];
        foreach ($activities as $groupActivities) {
            foreach ($groupActivities as $activity) {
                $label = $activity['name'];
                if (isset($activityChoices[$label])) {
                    $label .= ' #' . $activity['id'];
                }
                $activityChoices[$label] = $activity['id'];
            }
        }

        $scenarios = $this->catalog->getScenarioCatalog();
        $speciesChoices = [];
        $boardChoices = [];
        $boardOptionChoices = [];
        foreach ($scenarios['solidWoods'] as $key => $species) {
            $speciesChoices[$species['label']] = $key;
        }
        foreach ($scenarios['boards'] as $family => $board) {
            $boardChoices[$board['label']] = $family;
            foreach ($board['options'] as $index => $boardOption) {
                $boardOptionChoices[$board['label'] . ' — ' . $boardOption['thicknessMm'] . ' mm'] = $family . ':' . $index;
            }
            if ($board['unknown'] !== null) {
                $boardOptionChoices[$board['label'] . ' — backend.emission.wood.board.other'] = $family . ':other';
            }
        }

        $builder
            ->add('registeredAt', DateType::class, [
                'label' => 'backend.emission.form.registered_at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('subCategory', ChoiceType::class, [
                'mapped' => false,
                'label' => 'backend.emission.energy.subcategory',
                'choices' => [
                    'backend.emission.subcat.madera' => 'madera',
                    'backend.emission.wood.other_materials' => 'generic',
                ],
                'placeholder' => 'backend.common.select',
                'data' => $options['initial_subcategory'],
            ])
            ->add('activityId', ChoiceType::class, [
                'mapped' => false,
                'label' => 'backend.emission.form.activity',
                'choices' => $activityChoices,
                'placeholder' => 'backend.emission.form.activity_placeholder',
                'data' => $options['initial_activity_id'],
            ])
            ->add('method', ChoiceType::class, [
                'mapped' => false,
                'label' => 'backend.emission.wood.method.label',
                'choices' => [
                    'backend.emission.wood.method.known_weight' => 'known_weight',
                    'backend.emission.wood.method.unknown_dimensions' => 'unknown_dimensions',
                    'backend.emission.wood.method.solid_species' => 'solid_species',
                    'backend.emission.wood.method.board' => 'board',
                ],
                'placeholder' => '—',
                'data' => $inputs['method'] ?? null,
            ])
            ->add('certification', ChoiceType::class, [
                'mapped' => false,
                'label' => 'backend.emission.wood.certification.label',
                'choices' => [
                    'backend.emission.wood.certification.fsc' => 'fsc',
                    'backend.emission.wood.certification.pefc' => 'pefc',
                    'backend.emission.wood.certification.none' => 'none',
                ],
                'data' => $inputs['certification'] ?? 'none',
            ])
            ->add('quantity', IntegerType::class, [
                'mapped' => false,
                'label' => 'backend.emission.wood.quantity',
                'data' => $inputs['quantity'] ?? 1,
                'constraints' => [new Positive()],
                'attr' => ['min' => 1],
            ])
            ->add('amount', NumberType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'backend.emission.form.amount',
                'data' => $options['generic_amount'],
                'attr' => ['min' => 0, 'step' => 'any'],
            ])
            ->add('inputWeightKg', NumberType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'backend.emission.wood.input_weight_kg',
                'data' => $inputs['inputWeightKg'] ?? null,
                'attr' => ['min' => 0, 'step' => 'any'],
            ])
            ->add('woodClassification', ChoiceType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'backend.emission.wood.classification.label',
                'choices' => [
                    'backend.emission.wood.classification.unknown' => 'unknown',
                    'backend.emission.wood.classification.solid' => 'solid',
                    'backend.emission.wood.classification.non_solid' => 'non_solid',
                ],
                'data' => $inputs['woodClassification'] ?? 'unknown',
            ])
            ->add('speciesKey', ChoiceType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'backend.emission.wood.species',
                'choices' => $speciesChoices,
                'placeholder' => 'backend.common.select',
                'data' => $inputs['speciesKey'] ?? null,
            ])
            ->add('boardFamily', ChoiceType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'backend.emission.wood.board.family',
                'choices' => $boardChoices,
                'placeholder' => 'backend.common.select',
                'data' => $inputs['boardFamily'] ?? null,
            ])
            ->add('boardOption', ChoiceType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'backend.emission.wood.board.thickness',
                'choices' => $boardOptionChoices,
                'placeholder' => 'backend.common.select',
                'data' => $inputs['boardOption'] ?? null,
            ])
            ->add('manualBoardThicknessMm', NumberType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'backend.emission.wood.board.manual_thickness_mm',
                'data' => $inputs['manualBoardThicknessMm'] ?? null,
                'attr' => ['min' => 0, 'step' => 'any'],
            ]);

        foreach (['thicknessM', 'lengthM', 'widthM'] as $field) {
            $builder->add($field, NumberType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'backend.emission.wood.' . match ($field) {
                    'thicknessM' => 'thickness_m',
                    'lengthM' => 'length_m',
                    'widthM' => 'width_m',
                },
                'data' => $inputs[$field] ?? null,
                'attr' => ['min' => 0, 'step' => 'any'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmissionRecord::class,
            'translation_domain' => 'messages',
            'wood_details' => [],
            'material_activities' => [],
            'initial_subcategory' => null,
            'initial_activity_id' => null,
            'generic_amount' => null,
        ]);
        $resolver->setAllowedTypes('wood_details', 'array');
        $resolver->setAllowedTypes('material_activities', 'array');
        $resolver->setAllowedTypes('initial_subcategory', ['null', 'string']);
        $resolver->setAllowedTypes('initial_activity_id', ['null', 'int']);
        $resolver->setAllowedTypes('generic_amount', ['null', 'float']);
    }
}
