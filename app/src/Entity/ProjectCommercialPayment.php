<?php

namespace App\Entity;

use App\Repository\ProjectCommercialPaymentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One immutable commercial quote and its Stripe payment lifecycle, per purchase.
 * The project tier remains authoritative; this entity never grants feature access.
 */
#[ORM\Entity(repositoryClass: ProjectCommercialPaymentRepository::class)]
#[ORM\Table(name: 'project_commercial_payment')]
#[ORM\UniqueConstraint(name: 'uniq_project_commercial_payment_session', columns: ['stripe_checkout_session_id'])]
#[ORM\Index(name: 'idx_project_commercial_payment_project_status', columns: ['project_id', 'status'])]
class ProjectCommercialPayment
{
    public const STATUS_CREATED = 'created';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_PAID_REVIEW = 'paid_review';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Project $project;

    #[ORM\Column(name: 'from_tier', length: 20)]
    private string $fromTier;

    #[ORM\Column(name: 'target_tier', length: 20)]
    private string $targetTier;

    #[ORM\Column(name: 'price_band', length: 10)]
    private string $priceBand;

    #[ORM\Column(name: 'amount_cents', type: 'integer')]
    private int $amountCents;

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    private string $currency = 'EUR';

    #[ORM\Column(name: 'pricing_fingerprint', length: 64)]
    private string $pricingFingerprint;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_CREATED;

    #[ORM\Column(name: 'stripe_checkout_session_id', length: 255, nullable: true)]
    private ?string $stripeCheckoutSessionId = null;

    #[ORM\Column(name: 'stripe_checkout_url', length: 2048, nullable: true)]
    private ?string $stripeCheckoutUrl = null;

    #[ORM\Column(name: 'stripe_checkout_expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $stripeCheckoutExpiresAt = null;

    #[ORM\Column(name: 'stripe_payment_intent_id', length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column(name: 'stripe_invoice_id', length: 255, nullable: true)]
    private ?string $stripeInvoiceId = null;

    #[ORM\Column(name: 'stripe_customer_id', length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'paid_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(name: 'review_reason', length: 100, nullable: true)]
    private ?string $reviewReason = null;

    public function __construct(Project $project, string $fromTier, string $targetTier, string $priceBand, int $amountCents, string $pricingFingerprint)
    {
        if (!in_array([$fromTier, $targetTier], [
            [Project::COMMERCIAL_TIER_BASIC, Project::COMMERCIAL_TIER_STANDARD],
            [Project::COMMERCIAL_TIER_BASIC, Project::COMMERCIAL_TIER_PRO],
            [Project::COMMERCIAL_TIER_STANDARD, Project::COMMERCIAL_TIER_PRO],
        ], true)) {
            throw new \InvalidArgumentException('Invalid project commercial upgrade.');
        }
        if (!in_array($priceBand, ['low', 'mid', 'high'], true)) {
            throw new \InvalidArgumentException('Invalid price band.');
        }
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Commercial payment amount must be positive.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $pricingFingerprint) !== 1) {
            throw new \InvalidArgumentException('Invalid pricing fingerprint.');
        }

        $this->project = $project;
        $this->fromTier = $fromTier;
        $this->targetTier = $targetTier;
        $this->priceBand = $priceBand;
        $this->amountCents = $amountCents;
        $this->pricingFingerprint = $pricingFingerprint;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProject(): Project { return $this->project; }
    public function getFromTier(): string { return $this->fromTier; }
    public function getTargetTier(): string { return $this->targetTier; }
    public function getPriceBand(): string { return $this->priceBand; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function getCurrency(): string { return $this->currency; }
    public function getPricingFingerprint(): string { return $this->pricingFingerprint; }
    public function getStatus(): string { return $this->status; }
    public function getStripeCheckoutSessionId(): ?string { return $this->stripeCheckoutSessionId; }
    public function getStripeCheckoutUrl(): ?string { return $this->stripeCheckoutUrl; }
    public function getStripeCheckoutExpiresAt(): ?\DateTimeImmutable { return $this->stripeCheckoutExpiresAt; }
    public function getStripePaymentIntentId(): ?string { return $this->stripePaymentIntentId; }
    public function getStripeInvoiceId(): ?string { return $this->stripeInvoiceId; }
    public function getStripeCustomerId(): ?string { return $this->stripeCustomerId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function getReviewReason(): ?string { return $this->reviewReason; }

    public function markCheckoutCreated(string $sessionId, string $url, ?\DateTimeImmutable $expiresAt = null): self
    {
        if ($this->status !== self::STATUS_CREATED || trim($sessionId) === '' || trim($url) === '') {
            throw new \LogicException('Invalid Stripe checkout creation state.');
        }
        $this->stripeCheckoutSessionId = $sessionId;
        $this->stripeCheckoutUrl = $url;
        $this->stripeCheckoutExpiresAt = $expiresAt;
        $this->status = self::STATUS_PENDING;

        return $this;
    }

    /** A verified Stripe webhook can arrive before the creating HTTP request persists the session. */
    public function bindSessionFromVerifiedWebhook(string $sessionId): self
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            throw new \InvalidArgumentException('Stripe session ID is required.');
        }
        if ($this->stripeCheckoutSessionId === $sessionId) {
            return $this;
        }
        if ($this->status !== self::STATUS_CREATED || $this->stripeCheckoutSessionId !== null) {
            throw new \LogicException('Cannot attach a different Stripe session.');
        }
        $this->stripeCheckoutSessionId = $sessionId;
        $this->status = self::STATUS_PENDING;

        return $this;
    }

    /** A verified charge that cannot safely activate the project must block another charge. */
    public function markPaidForReview(string $reason, ?string $paymentIntentId = null, ?string $invoiceId = null, ?string $customerId = null): self
    {
        if ($this->status === self::STATUS_PAID_REVIEW) {
            return $this;
        }
        if (!in_array($this->status, [self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_EXPIRED], true)) {
            throw new \LogicException('Payment is not eligible for manual review.');
        }
        if (!in_array($reason, [
            'amount_mismatch', 'currency_mismatch', 'project_tier_changed',
            'project_pricing_changed', 'payment_arrived_after_interruption',
        ], true)) {
            throw new \InvalidArgumentException('Invalid review reason.');
        }
        $this->reviewReason = $reason;
        $this->stripePaymentIntentId = $paymentIntentId;
        $this->stripeInvoiceId = $invoiceId;
        $this->stripeCustomerId = $customerId;
        $this->paidAt = new \DateTimeImmutable();
        $this->status = self::STATUS_PAID_REVIEW;

        return $this;
    }

    public function markPaid(?string $paymentIntentId = null, ?string $invoiceId = null, ?string $customerId = null): self
    {
        if ($this->status === self::STATUS_PAID) {
            return $this;
        }
        if ($this->status !== self::STATUS_PENDING) {
            throw new \LogicException('Only a pending commercial payment can be confirmed.');
        }
        $this->stripePaymentIntentId = $paymentIntentId;
        $this->stripeInvoiceId = $invoiceId;
        $this->stripeCustomerId = $customerId;
        $this->paidAt = new \DateTimeImmutable();
        $this->status = self::STATUS_PAID;

        return $this;
    }

    /** Attach a late-arriving invoice to an already verified paid payment, without changing its state. */
    public function attachVerifiedInvoice(string $invoiceId, ?string $customerId = null): self
    {
        if ($this->status !== self::STATUS_PAID || !str_starts_with($invoiceId, 'in_')) {
            throw new \LogicException('Only a paid payment can receive a verified Stripe invoice.');
        }
        if ($this->stripeInvoiceId !== null && !hash_equals($this->stripeInvoiceId, $invoiceId)) {
            throw new \DomainException('A different Stripe invoice is already associated with this payment.');
        }
        if ($customerId !== null && $this->stripeCustomerId !== null && !hash_equals($this->stripeCustomerId, $customerId)) {
            throw new \DomainException('A different Stripe customer is already associated with this payment.');
        }
        $this->stripeInvoiceId = $invoiceId;
        if ($this->stripeCustomerId === null && $customerId !== null) {
            $this->stripeCustomerId = $customerId;
        }

        return $this;
    }

    public function markInterrupted(string $status): self
    {
        if (!in_array($status, [self::STATUS_EXPIRED, self::STATUS_FAILED], true)) {
            throw new \InvalidArgumentException('Invalid interrupted payment status.');
        }
        if (in_array($this->status, [self::STATUS_PAID, self::STATUS_PAID_REVIEW], true)) {
            return $this; // A delayed failure event cannot revoke a confirmed payment.
        }
        if (!in_array($this->status, [self::STATUS_CREATED, self::STATUS_PENDING], true)) {
            return $this;
        }
        $this->status = $status;

        return $this;
    }
}
