<?php

namespace App\Entity;

use App\Repository\ProjectSubscriptionRepository;
use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProjectSubscriptionRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'project_subscription')]
class ProjectSubscription
{
    use TimestampableTrait;

    public const TIER_BASIC = 'basic';
    public const TIER_STANDARD = 'standard';
    public const TIER_PRO = 'pro';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_PAYPAL = 'paypal';
    public const SOURCE_STRIPE = 'stripe';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'subscription')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::TIER_BASIC, self::TIER_STANDARD, self::TIER_PRO])]
    private string $tier = self::TIER_BASIC;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::STATUS_ACTIVE, self::STATUS_PENDING_PAYMENT, self::STATUS_CANCELLED])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::SOURCE_SYSTEM, self::SOURCE_MANUAL, self::SOURCE_PAYPAL, self::SOURCE_STRIPE])]
    private string $source = self::SOURCE_SYSTEM;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $paidAmountCents = null;

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    private string $currency = 'EUR';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $paymentReference = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCheckoutSessionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeInvoiceId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeHostedInvoiceUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeInvoicePdfUrl = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $lastPaymentStatus = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $targetTier = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): self
    {
        $this->project = $project;
        return $this;
    }

    public function getTier(): string
    {
        return $this->tier;
    }

    public function setTier(string $tier): self
    {
        $this->tier = $tier;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;
        return $this;
    }

    public function getPaidAmountCents(): ?int
    {
        return $this->paidAmountCents;
    }

    public function setPaidAmountCents(?int $paidAmountCents): self
    {
        $this->paidAmountCents = $paidAmountCents;
        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;
        return $this;
    }

    public function getPaymentReference(): ?string
    {
        return $this->paymentReference;
    }

    public function setPaymentReference(?string $paymentReference): self
    {
        $this->paymentReference = $paymentReference;
        return $this;
    }

    public function getStripeCheckoutSessionId(): ?string
    {
        return $this->stripeCheckoutSessionId;
    }

    public function setStripeCheckoutSessionId(?string $stripeCheckoutSessionId): self
    {
        $this->stripeCheckoutSessionId = $stripeCheckoutSessionId;
        return $this;
    }

    public function getStripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function setStripePaymentIntentId(?string $stripePaymentIntentId): self
    {
        $this->stripePaymentIntentId = $stripePaymentIntentId;
        return $this;
    }

    public function getStripeInvoiceId(): ?string
    {
        return $this->stripeInvoiceId;
    }

    public function setStripeInvoiceId(?string $stripeInvoiceId): self
    {
        $this->stripeInvoiceId = $stripeInvoiceId;
        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): self
    {
        $this->stripeCustomerId = $stripeCustomerId;
        return $this;
    }

    public function getStripeHostedInvoiceUrl(): ?string
    {
        return $this->stripeHostedInvoiceUrl;
    }

    public function setStripeHostedInvoiceUrl(?string $stripeHostedInvoiceUrl): self
    {
        $this->stripeHostedInvoiceUrl = $stripeHostedInvoiceUrl;
        return $this;
    }

    public function getStripeInvoicePdfUrl(): ?string
    {
        return $this->stripeInvoicePdfUrl;
    }

    public function setStripeInvoicePdfUrl(?string $stripeInvoicePdfUrl): self
    {
        $this->stripeInvoicePdfUrl = $stripeInvoicePdfUrl;
        return $this;
    }

    public function getLastPaymentStatus(): ?string
    {
        return $this->lastPaymentStatus;
    }

    public function setLastPaymentStatus(?string $lastPaymentStatus): self
    {
        $this->lastPaymentStatus = $lastPaymentStatus;
        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): self
    {
        $this->paidAt = $paidAt;
        return $this;
    }

    public function getTargetTier(): ?string
    {
        return $this->targetTier;
    }

    public function setTargetTier(?string $targetTier): self
    {
        $this->targetTier = $targetTier;
        return $this;
    }
}
