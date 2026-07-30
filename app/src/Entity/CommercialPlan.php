<?php

namespace App\Entity;

use App\Enum\CommercialPhase;
use App\Entity\Traits\TimestampableTrait;
use App\Repository\CommercialPlanRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CommercialPlanRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'commercial_plan')]
#[ORM\UniqueConstraint(name: 'uniq_commercial_plan_phase_code', columns: ['phase', 'code'])]
class CommercialPlan
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 20, enumType: CommercialPhase::class)]
    #[Assert\NotNull]
    private CommercialPhase $phase;

    #[ORM\Column(length: 20)]
    private string $code = 'basic';

    #[ORM\Column(length: 100)]
    private string $name = 'Basic';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $priceAmount = null;

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    private string $priceCurrency = 'EUR';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePriceId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeUpgradeFromStandardPriceId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxEvidenceCount = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $watermarkEnabled = false;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'json')]
    private array $features = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function assertPhaseIsSet(): void
    {
        if (!isset($this->phase)) {
            throw new \LogicException('CommercialPlan phase must be set before persisting or updating.');
        }
    }

    public function getPhase(): CommercialPhase
    {
        return $this->phase;
    }

    public function setPhase(CommercialPhase $phase): self
    {
        $this->phase = $phase;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = strtolower(trim($code));

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getPriceAmount(): ?int
    {
        return $this->priceAmount;
    }

    public function setPriceAmount(?int $priceAmount): self
    {
        $this->priceAmount = $priceAmount;

        return $this;
    }

    public function getPriceCurrency(): string
    {
        return $this->priceCurrency;
    }

    public function setPriceCurrency(string $priceCurrency): self
    {
        $this->priceCurrency = strtoupper(trim($priceCurrency));

        return $this;
    }

    public function getStripePriceId(): ?string
    {
        return $this->stripePriceId;
    }

    public function setStripePriceId(?string $stripePriceId): self
    {
        $this->stripePriceId = $stripePriceId !== null ? trim($stripePriceId) : null;

        if ($this->stripePriceId === '') {
            $this->stripePriceId = null;
        }

        return $this;
    }

    public function getStripeUpgradeFromStandardPriceId(): ?string
    {
        return $this->stripeUpgradeFromStandardPriceId;
    }

    public function setStripeUpgradeFromStandardPriceId(?string $stripeUpgradeFromStandardPriceId): self
    {
        $this->stripeUpgradeFromStandardPriceId = $stripeUpgradeFromStandardPriceId !== null ? trim($stripeUpgradeFromStandardPriceId) : null;

        if ($this->stripeUpgradeFromStandardPriceId === '') {
            $this->stripeUpgradeFromStandardPriceId = null;
        }

        return $this;
    }

    public function getMaxEvidenceCount(): ?int
    {
        return $this->maxEvidenceCount;
    }

    public function setMaxEvidenceCount(?int $maxEvidenceCount): self
    {
        $this->maxEvidenceCount = $maxEvidenceCount;

        return $this;
    }

    public function isWatermarkEnabled(): bool
    {
        return $this->watermarkEnabled;
    }

    public function setWatermarkEnabled(bool $watermarkEnabled): self
    {
        $this->watermarkEnabled = $watermarkEnabled;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getFeatures(): array
    {
        return $this->features;
    }

    public function setFeatures(array $features): self
    {
        $normalized = [];
        foreach ($features as $feature => $value) {
            $normalized[trim((string) $feature)] = $value;
        }

        $this->features = $normalized;

        return $this;
    }

    public function hasFeature(string $feature): bool
    {
        return array_key_exists(trim($feature), $this->features);
    }

    public function getFeature(string $feature, mixed $default = null): mixed
    {
        $feature = trim($feature);

        return array_key_exists($feature, $this->features)
            ? $this->features[$feature]
            : $default;
    }

    public function setFeature(string $feature, mixed $value): self
    {
        $this->features[trim($feature)] = $value;

        return $this;
    }

    /**
     * @return int[]
     */
    public function getAllowedScores(): array
    {
        $scores = $this->features['allowed_scores'] ?? [];
        if (!is_array($scores)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $score): int => (int) $score, $scores));
    }

    /**
     * @param int[] $scores
     */
    public function setAllowedScores(array $scores): self
    {
        $this->features['allowed_scores'] = array_values(array_map(static fn (mixed $score): int => (int) $score, $scores));

        return $this;
    }
}
