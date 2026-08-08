<?php

namespace App\Form;

use App\Entity\EmissionRecord;
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
