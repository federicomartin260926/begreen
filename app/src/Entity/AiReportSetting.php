<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\Table(name: 'ai_report_setting')]
class AiReportSetting
{
    public const SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['openai', 'anthropic'])]
    private string $provider = 'openai';

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $openAiModel = '';

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $anthropicModel = '';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20000)]
    private string $generalInstructions = '';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20000)]
    private string $executiveSummaryInstructions = '';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20000)]
    private string $categoryInstructions = '';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20000)]
    private string $futureCategoryInstructions = '';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20000)]
    private string $avoidInstructions = '';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20000)]
    private string $finalConclusionInstructions = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = strtolower(trim($provider));

        return $this;
    }

    public function getOpenAiModel(): string
    {
        return $this->openAiModel;
    }

    public function setOpenAiModel(string $openAiModel): self
    {
        $this->openAiModel = trim($openAiModel);

        return $this;
    }

    public function getAnthropicModel(): string
    {
        return $this->anthropicModel;
    }

    public function setAnthropicModel(string $anthropicModel): self
    {
        $this->anthropicModel = trim($anthropicModel);

        return $this;
    }

    public function getGeneralInstructions(): string
    {
        return $this->generalInstructions;
    }

    public function setGeneralInstructions(string $instructions): self
    {
        $this->generalInstructions = trim($instructions);

        return $this;
    }

    public function getExecutiveSummaryInstructions(): string
    {
        return $this->executiveSummaryInstructions;
    }

    public function setExecutiveSummaryInstructions(string $instructions): self
    {
        $this->executiveSummaryInstructions = trim($instructions);

        return $this;
    }

    public function getCategoryInstructions(): string
    {
        return $this->categoryInstructions;
    }

    public function setCategoryInstructions(string $instructions): self
    {
        $this->categoryInstructions = trim($instructions);

        return $this;
    }

    public function getFutureCategoryInstructions(): string
    {
        return $this->futureCategoryInstructions;
    }

    public function setFutureCategoryInstructions(string $instructions): self
    {
        $this->futureCategoryInstructions = trim($instructions);

        return $this;
    }

    public function getAvoidInstructions(): string
    {
        return $this->avoidInstructions;
    }

    public function setAvoidInstructions(string $instructions): self
    {
        $this->avoidInstructions = trim($instructions);

        return $this;
    }

    public function getFinalConclusionInstructions(): string
    {
        return $this->finalConclusionInstructions;
    }

    public function setFinalConclusionInstructions(string $instructions): self
    {
        $this->finalConclusionInstructions = trim($instructions);

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }

    #[Assert\Callback]
    public function validateActiveModel(ExecutionContextInterface $context): void
    {
        $property = $this->provider === 'anthropic' ? 'anthropicModel' : 'openAiModel';
        $model = $this->provider === 'anthropic' ? $this->anthropicModel : $this->openAiModel;
        if ($model === '') {
            $context->buildViolation('backend.ai.validation.active_model_required')
                ->atPath($property)
                ->addViolation();
        }
    }
}
