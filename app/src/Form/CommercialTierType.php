<?php

namespace App\Form;

use App\Entity\CommercialPlan;
use App\Enum\CommercialPhase;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

/** One commercial tier with two internal capability configurations. */
final class CommercialTierType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var CommercialPlan $elaboration */
        $elaboration = $options['elaboration_plan'];
        /** @var CommercialPlan $implementation */
        $implementation = $options['implementation_plan'];
        if ($elaboration->getPhase() !== CommercialPhase::ELABORATION
            || $implementation->getPhase() !== CommercialPhase::IMPLEMENTATION
            || $elaboration->getCode() !== $implementation->getCode()) {
            throw new \LogicException('The commercial tier form requires matching Elaboration and Implementation plans.');
        }

        $builder
            ->add('name', TextType::class, [
                'label' => 'backend.commercial_plans.form.name',
                'mapped' => false,
                'data' => $elaboration->getName(),
                'constraints' => [new NotBlank(), new Length(max: 100)],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'backend.commercial_plans.form.active',
                'mapped' => false,
                'required' => false,
                'data' => $elaboration->isActive() && $implementation->isActive(),
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'backend.commercial_plans.form.sort_order',
                'mapped' => false,
                'data' => $elaboration->getSortOrder(),
                'attr' => ['min' => 0],
                'constraints' => [new PositiveOrZero()],
            ])
            ->add('elaboration', CommercialPlanType::class, [
                'mapped' => false,
                'data' => $elaboration,
                'tier_view' => true,
            ])
            ->add('implementation', CommercialPlanType::class, [
                'mapped' => false,
                'data' => $implementation,
                'tier_view' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'elaboration_plan' => null,
            'implementation_plan' => null,
        ]);
        $resolver->setRequired(['elaboration_plan', 'implementation_plan']);
        $resolver->setAllowedTypes('elaboration_plan', CommercialPlan::class);
        $resolver->setAllowedTypes('implementation_plan', CommercialPlan::class);
    }
}
