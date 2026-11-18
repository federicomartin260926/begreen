<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(
    name: 'measure_verification_source',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_measure_verification_source', columns: ['measure_id', 'verification_source_id'])
    ]
)]
class MeasureVerificationSource
{
    public const SUGGESTED_QUALITIES = ['Alto', 'Medio/alto', 'Medio', 'Medio/bajo', 'Bajo'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'verificationSourceLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Measure $measure = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?VerificationSource $verificationSource = null;

    #[ORM\Column(name: 'suggested_quality', length: 10)]
    #[Assert\Choice(choices: self::SUGGESTED_QUALITIES)]
    #[Assert\NotBlank]
    private string $suggestedQuality;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMeasure(): ?Measure
    {
        return $this->measure;
    }

    public function setMeasure(?Measure $measure): self
    {
        $this->measure = $measure;
        return $this;
    }

    public function getVerificationSource(): ?VerificationSource
    {
        return $this->verificationSource;
    }

    public function setVerificationSource(?VerificationSource $verificationSource): self
    {
        $this->verificationSource = $verificationSource;
        return $this;
    }

    public function getSuggestedQuality(): string
    {
        return $this->suggestedQuality;
    }

    public function setSuggestedQuality(string $suggestedQuality): self
    {
        if (!in_array($suggestedQuality, self::SUGGESTED_QUALITIES, true)) {
            throw new \InvalidArgumentException(sprintf('Calidad sugerida no válida: "%s".', $suggestedQuality));
        }

        $this->suggestedQuality = $suggestedQuality;
        return $this;
    }
}
