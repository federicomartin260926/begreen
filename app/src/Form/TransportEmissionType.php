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

class TransportEmissionType extends AbstractType
{
    public function __construct(
        private readonly EmissionActivityRepository $activityRepository,
        private readonly TranslatorInterface $translator
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var EmissionRecord|null $record */
        $record      = $options['data'] ?? null;
        $categoryId  = $options['categoryId'] ?? null;
        $categoryIdI = $categoryId !== null ? (int) $categoryId : null;

        // Códigos de subcategoría por categoría (ID) desde el repo
        $codes = $categoryIdI ? $this->activityRepository->getSubcategoriesByCategoryId($categoryIdI) : [];

        // choices: "Etiqueta traducida" => "codigo"
        $choices = [];
        foreach ($codes as $code) {
            if ($code === null || $code === '') continue;
            $label = $this->translator->trans('backend.emission.subcat.' . $code, [], 'messages');
            $choices[$label] = (string) $code;
        }
        ksort($choices, SORT_NATURAL | SORT_FLAG_CASE);

        // Preselección en edición
        $initialSubcat = $record?->getActivity()?->getSubcategory() ?: null;

        $builder
            ->add('registeredAt', DateType::class, [
                'label' => 'backend.emission.form.registered_at',
                'translation_domain' => 'messages',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
                'html5'  => true,
                'attr'   => ['class' => 'form-control'],
            ])
            ->add('subCategory', ChoiceType::class, [
                'label' => 'backend.emission.transport.subcategory',
                'translation_domain' => 'messages',
                'choices' => $choices,
                'choice_translation_domain' => false,
                'placeholder' => 'backend.common.select',
                'required' => true,
                'mapped' => false,
                'data' => $initialSubcat,
                'attr' => [
                    'class' => 'form-select',
                    'data-transport-form-target' => 'subCategory',
                    'data-category-id' => $categoryIdI,
                ],
            ])
            ->add('amount', NumberType::class, [
                'label' => 'backend.emission.form.amount',
                'translation_domain' => 'messages',
                'scale' => 2,
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'step'  => 'any',
                    'id'    => 'input-amount',
                    'readonly' => true, // si tu JS lo calcula
                ],
            ])
            ->add('calculationDetails', HiddenType::class, [
                'mapped' => true,
                'attr' => ['data-transport-form-target' => 'detailsField'],
                'empty_data' => '',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => EmissionRecord::class,
            'categoryId'         => null,
            'translation_domain' => 'messages',
        ]);
        $resolver->setAllowedTypes('categoryId', ['null', 'int', 'string']);
    }
}
