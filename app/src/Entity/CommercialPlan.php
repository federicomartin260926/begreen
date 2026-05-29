<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\CommercialPlanRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommercialPlanRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'commercial_plan')]
class CommercialPlan
{
    use TimestampableTrait;

    // `public_comments` es la clave canónica; `custom_comments` queda como alias compatible.
    private const FEATURE_ALIASES = [
        'sustainability_plan.custom_comments' => 'sustainability_plan.public_comments',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private string $code = 'basic';

    #[ORM\Column(length: 100)]
    private string $name = 'Basic';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $priceAmount = null;

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    private string $priceCurrency = 'EUR';

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
            $normalized[$this->normalizeFeatureKey((string) $feature)] = $value;
        }

        $this->features = $normalized;

        return $this;
    }

    public function hasFeature(string $feature): bool
    {
        foreach ($this->featureKeysForLookup($feature) as $candidate) {
            if (array_key_exists($candidate, $this->features)) {
                return true;
            }
        }

        return false;
    }

    public function getFeature(string $feature, mixed $default = null): mixed
    {
        foreach ($this->featureKeysForLookup($feature) as $candidate) {
            if (array_key_exists($candidate, $this->features)) {
                return $this->features[$candidate];
            }
        }

        return $default;
    }

    public function setFeature(string $feature, mixed $value): self
    {
        $this->features[$this->normalizeFeatureKey($feature)] = $value;

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

    /**
     * @return string[]
     */
    private function featureKeysForLookup(string $feature): array
    {
        $normalized = $this->normalizeFeatureKey($feature);
        $keys = [$normalized];

        if ($normalized !== $feature) {
            $keys[] = $feature;
        }

        return array_values(array_unique($keys));
    }

    private function normalizeFeatureKey(string $feature): string
    {
        $feature = trim($feature);

        return self::FEATURE_ALIASES[$feature] ?? $feature;
    }
}
