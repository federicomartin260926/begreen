<?php

namespace App\Form;

use App\Entity\AiReportSetting;
use App\Service\Ai\AiProviderAvailability;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AiReportSettingType extends AbstractType
{
    public function __construct(
        private readonly AiProviderAvailability $providerAvailability,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currentProvider = $options['data'] instanceof AiReportSetting
            ? $options['data']->getProvider()
            : '';

        $builder
            ->add('provider', ChoiceType::class, [
                'label' => 'backend.ai.form.provider',
                'choices' => [
                    'Anthropic' => 'anthropic',
                    'OpenAI' => 'openai',
                ],
                'choice_label' => fn (mixed $choice, string $key, mixed $value): string => sprintf(
                    'backend.ai.providers.%s%s',
                    $value,
                    $this->providerAvailability->isAvailable((string) $value) ? '' : '_unavailable',
                ),
                'choice_attr' => fn (mixed $choice, string $key, mixed $value): array => [
                    'disabled' => !$this->providerAvailability->isAvailable((string) $value),
                ],
                'empty_data' => $currentProvider,
            ])
            ->add('anthropicModel', null, ['label' => 'backend.ai.form.anthropic_model'])
            ->add('openAiModel', null, ['label' => 'backend.ai.form.openai_model'])
            ->add('generalInstructions', TextareaType::class, $this->textarea('backend.ai.form.general'))
            ->add('executiveSummaryInstructions', TextareaType::class, $this->textarea('backend.ai.form.executive_summary'))
            ->add('categoryInstructions', TextareaType::class, $this->textarea('backend.ai.form.category'))
            ->add('avoidInstructions', TextareaType::class, $this->textarea('backend.ai.form.avoid'))
            ->add('finalConclusionInstructions', TextareaType::class, $this->textarea('backend.ai.form.final_conclusion'));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AiReportSetting::class,
            'csrf_token_id' => 'ai_report_setting',
        ]);
    }

    /** @return array<string, mixed> */
    private function textarea(string $label): array
    {
        return [
            'label' => $label,
            'attr' => ['rows' => 6],
        ];
    }
}
