<?php

namespace App\Form;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EmissionFactorType extends AbstractType
{
    public function __construct(
        private readonly EmissionFactorKeyGenerator $keyGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $categoryKeys = $options['category_keys'];
        $builder
            ->add('categoryKey', ChoiceType::class, [
                'label' => 'backend.admin.emission_factor.fields.category_key',
                'choices' => array_combine($categoryKeys, $categoryKeys),
                'choice_translation_domain' => false,
                'constraints' => [new NotBlank(), new Length(max: 50)],
            ])
            ->add('factorId', TextType::class, [
                'label' => 'backend.admin.emission_factor.fields.factor_id',
                'constraints' => [new NotBlank(), new Length(max: 64)],
            ])
            ->add('criteria', TextareaType::class, [
                'label' => 'backend.admin.emission_factor.fields.criteria',
                'attr' => ['rows' => 9, 'class' => 'font-monospace'],
                'invalid_message' => $this->translator->trans('backend.admin.emission_factor.validation.invalid_json_object'),
            ])
            ->add('temporalType', ChoiceType::class, [
                'label' => 'backend.admin.emission_factor.fields.temporal_type',
                'choices' => [
                    EmissionFactor::TEMPORAL_TYPE_ANNUAL => EmissionFactor::TEMPORAL_TYPE_ANNUAL,
                    EmissionFactor::TEMPORAL_TYPE_VERSIONED => EmissionFactor::TEMPORAL_TYPE_VERSIONED,
                    EmissionFactor::TEMPORAL_TYPE_RULE => EmissionFactor::TEMPORAL_TYPE_RULE,
                    EmissionFactor::TEMPORAL_TYPE_COMPOSITE => EmissionFactor::TEMPORAL_TYPE_COMPOSITE,
                    EmissionFactor::TEMPORAL_TYPE_PROXY_LCA => EmissionFactor::TEMPORAL_TYPE_PROXY_LCA,
                ],
                'choice_translation_domain' => false,
            ])
            ->add('activityYear', IntegerType::class, [
                'label' => 'backend.admin.emission_factor.fields.activity_year',
                'required' => false,
            ])
            ->add('year', IntegerType::class, [
                'label' => 'backend.admin.emission_factor.fields.year',
                'required' => false,
            ])
            ->add('value', TextType::class, [
                'label' => 'backend.admin.emission_factor.fields.value',
                'required' => false,
                'empty_data' => null,
                'constraints' => [new Regex(pattern: '/^-?\d+(?:\.\d+)?$/', message: 'backend.admin.emission_factor.validation.invalid_decimal')],
            ])
            ->add('unit', TextType::class, [
                'label' => 'backend.admin.emission_factor.fields.unit',
                'constraints' => [new NotBlank(), new Length(max: 100)],
            ])
            ->add('source', TextType::class, [
                'label' => 'backend.admin.emission_factor.fields.source',
                'constraints' => [new NotBlank(), new Length(max: 255)],
            ])
            ->add('sourceDetail', TextareaType::class, [
                'label' => 'backend.admin.emission_factor.fields.source_detail',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('metadata', TextareaType::class, [
                'label' => 'backend.admin.emission_factor.fields.metadata',
                'required' => false,
                'attr' => ['rows' => 7, 'class' => 'font-monospace'],
                'invalid_message' => $this->translator->trans('backend.admin.emission_factor.validation.invalid_json_object'),
            ]);

        $builder->get('criteria')->addModelTransformer($this->jsonTransformer(false));
        $builder->get('metadata')->addModelTransformer($this->jsonTransformer(true));

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $factor = $event->getData();
            if (!$factor instanceof EmissionFactor || !$form->get('criteria')->isSynchronized()) {
                return;
            }

            if (EmissionFactor::TEMPORAL_TYPE_ANNUAL === $factor->getTemporalType() && null === $factor->getYear()) {
                $form->get('year')->addError(new FormError($this->translator->trans('backend.admin.emission_factor.validation.annual_year_required')));
            }

            $factor->setFunctionalKey($this->keyGenerator->generate($factor->getCriteria()));
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmissionFactor::class,
            'category_keys' => [],
        ]);
        $resolver->setAllowedTypes('category_keys', 'string[]');
    }

    private function jsonTransformer(bool $nullable): CallbackTransformer
    {
        return new CallbackTransformer(
            static function (?array $value) use ($nullable): string {
                if ($nullable && null === $value) {
                    return '';
                }
                if ([] === $value) {
                    return '{}';
                }

                return json_encode($value ?? [], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            },
            static function (mixed $value) use ($nullable): ?array {
                $json = trim((string) $value);
                if ('' === $json && $nullable) {
                    return null;
                }
                if ('' === $json) {
                    throw new TransformationFailedException('JSON object required.');
                }

                try {
                    $decodedObject = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
                    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new TransformationFailedException('Invalid JSON.', 0, $exception);
                }
                if (!is_object($decodedObject) || !is_array($decoded)) {
                    throw new TransformationFailedException('A JSON object is required.');
                }

                return $decoded;
            },
        );
    }
}
