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

    #[ORM\Column(type: 'integer')]
    #[Assert\Choice(choices: [1, 2, 3])]
    private int $priority;

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

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }
}
