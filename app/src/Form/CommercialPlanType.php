<?php

namespace App\Form;

use App\Entity\CommercialPlan;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

final class CommercialPlanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $plan = $builder->getData();
        $allowedScores = $plan instanceof CommercialPlan ? $plan->getAllowedScores() : [];
        $featureValue = static function (?CommercialPlan $plan, string $feature): bool {
            return $plan instanceof CommercialPlan ? (bool) $plan->getFeature($feature, false) : false;
        };

        $builder
            ->add('name', TextType::class, [
                'label' => 'backend.commercial_plans.form.name',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'backend.commercial_plans.form.description',
                'required' => false,
            ])
            ->add('priceAmount', IntegerType::class, [
                'label' => 'backend.commercial_plans.form.price_amount',
                'required' => false,
                'attr' => ['min' => 0],
                'constraints' => [new PositiveOrZero()],
            ])
            ->add('priceCurrency', TextType::class, [
                'label' => 'backend.commercial_plans.form.price_currency',
                'attr' => ['maxlength' => 3, 'style' => 'text-transform: uppercase;'],
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 3, max: 3),
                ],
            ])
            ->add('stripePriceId', TextType::class, [
                'label' => 'backend.commercial_plans.form.stripe_price_id',
                'required' => false,
                'attr' => ['maxlength' => 255],
                'help' => 'backend.commercial_plans.form.stripe_price_id_help',
            ]);

        if ($options['show_stripe_upgrade_from_standard_price_id']) {
            $builder->add('stripeUpgradeFromStandardPriceId', TextType::class, [
                'label' => 'backend.commercial_plans.form.stripe_upgrade_from_standard_price_id',
                'required' => false,
                'attr' => ['maxlength' => 255, 'placeholder' => 'price_...'],
                'help' => 'backend.commercial_plans.form.stripe_upgrade_from_standard_price_id_help',
            ]);
        }

        $builder
            ->add('maxEvidenceCount', IntegerType::class, [
                'label' => 'backend.commercial_plans.form.max_evidence_count',
                'required' => false,
                'attr' => ['min' => 0],
                'constraints' => [new PositiveOrZero()],
            ])
            ->add('watermarkEnabled', CheckboxType::class, [
                'label' => 'backend.commercial_plans.form.watermark_enabled',
                'required' => false,
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'backend.commercial_plans.form.active',
                'required' => false,
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'backend.commercial_plans.form.sort_order',
                'attr' => ['min' => 0],
                'constraints' => [new PositiveOrZero()],
            ])
            ->add('allowedScores', ChoiceType::class, [
                'label' => 'backend.commercial_plans.form.allowed_scores',
                'mapped' => false,
                'choices' => [
                    '1' => 1,
                    '2' => 2,
                    '3' => 3,
                    '4' => 4,
                    '5' => 5,
                ],
                'multiple' => true,
                'expanded' => true,
                'choice_translation_domain' => false,
                'data' => $allowedScores,
            ])
            ->add('pdfByDepartments', CheckboxType::class, [
                'label' => 'backend.commercial_plans.form.pdf_by_departments',
                'mapped' => false,
                'required' => false,
                'data' => $featureValue($plan, 'sustainability_plan.department_pdf'),
                'help' => 'backend.commercial_plans.form.pdf_by_departments_help',
            ])
            ->add('advancedExports', CheckboxType::class, [
                'label' => 'backend.commercial_plans.form.advanced_exports',
                'mapped' => false,
                'required' => false,
                'data' => $featureValue($plan, 'sustainability_plan.advanced_exports'),
            ])
            ->add('publicComments', CheckboxType::class, [
                'label' => 'backend.commercial_plans.form.public_comments',
                'mapped' => false,
                'required' => false,
                'data' => $featureValue($plan, 'sustainability_plan.public_comments'),
                'help' => 'backend.commercial_plans.form.public_comments_help',
            ])
            ->add('internalNotes', CheckboxType::class, [
                'label' => 'backend.commercial_plans.form.internal_notes',
                'mapped' => false,
                'required' => false,
                'data' => $featureValue($plan, 'sustainability_plan.internal_notes'),
            ])
            ->add('responsibles', CheckboxType::class, [
                'label' => 'backend.commercial_plans.form.responsibles',
                'mapped' => false,
                'required' => false,
                'data' => $featureValue($plan, 'sustainability_plan.responsibles'),
            ])
            ->add('checklist', CheckboxType::class, [
                'label' => 'backend.commercial_plans.form.checklist',
                'mapped' => false,
                'required' => false,
                'data' => $featureValue($plan, 'sustainability_plan.checklist'),
            ])
            ->add('customMeasures', CheckboxType::class, [
                'label' => 'backend.commercial_plans.form.custom_measures',
                'mapped' => false,
                'required' => false,
                'data' => $featureValue($plan, 'sustainability_plan.custom_measures'),
            ])
            ->add('validationSummary', CheckboxType::class, [
                'label' => 'backend.commercial_plans.form.validation_summary',
                'mapped' => false,
                'required' => false,
                'data' => $featureValue($plan, 'sustainability_plan.validation_summary'),
            ])
            ->add('branding', CheckboxType::class, [
                'label' => 'backend.commercial_plans.form.branding',
                'mapped' => false,
                'required' => false,
                'data' => $featureValue($plan, 'sustainability_plan.branding'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CommercialPlan::class,
            'show_stripe_upgrade_from_standard_price_id' => false,
        ]);

        $resolver->setAllowedTypes('show_stripe_upgrade_from_standard_price_id', 'bool');
    }
}
