<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\ProjectBillingDocumentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectBillingDocumentRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'project_billing_document')]
class ProjectBillingDocument
{
    use TimestampableTrait;

    public const PROVIDER_STRIPE = 'stripe';

    public const TYPE_INVOICE = 'invoice';
    public const TYPE_RECEIPT = 'receipt';

    public const STATUS_PAID = 'paid';
    public const STATUS_OPEN = 'open';
    public const STATUS_VOID = 'void';
    public const STATUS_UNKNOWN = 'unknown';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'billingDocuments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: ProjectSubscription::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProjectSubscription $subscription = null;

    #[ORM\Column(length: 20)]
    private string $provider = self::PROVIDER_STRIPE;

    #[ORM\Column(length: 20)]
    private string $type = self::TYPE_INVOICE;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $status = self::STATUS_UNKNOWN;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCheckoutSessionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeInvoiceId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $paymentReference = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $amountCents = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hostedInvoiceUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $invoicePdfUrl = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $localPath = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $downloadedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

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

    public function getSubscription(): ?ProjectSubscription
    {
        return $this->subscription;
    }

    public function setSubscription(?ProjectSubscription $subscription): self
    {
        $this->subscription = $subscription;

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $normalized = strtolower(trim($provider));
        $this->provider = $normalized !== '' ? $normalized : self::PROVIDER_STRIPE;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $normalized = strtolower(trim($type));
        $this->type = $normalized !== '' ? $normalized : self::TYPE_INVOICE;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status !== null ? strtolower(trim($status)) : null;

        if ($this->status === '') {
            $this->status = null;
        }

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

    public function getPaymentReference(): ?string
    {
        return $this->paymentReference;
    }

    public function setPaymentReference(?string $paymentReference): self
    {
        $this->paymentReference = $paymentReference;

        return $this;
    }

    public function getAmountCents(): ?int
    {
        return $this->amountCents;
    }

    public function setAmountCents(?int $amountCents): self
    {
        $this->amountCents = $amountCents;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency !== null ? strtoupper(trim($currency)) : null;

        if ($this->currency === '') {
            $this->currency = null;
        }

        return $this;
    }

    public function getHostedInvoiceUrl(): ?string
    {
        return $this->hostedInvoiceUrl;
    }

    public function setHostedInvoiceUrl(?string $hostedInvoiceUrl): self
    {
        $this->hostedInvoiceUrl = $hostedInvoiceUrl;

        return $this;
    }

    public function getInvoicePdfUrl(): ?string
    {
        return $this->invoicePdfUrl;
    }

    public function setInvoicePdfUrl(?string $invoicePdfUrl): self
    {
        $this->invoicePdfUrl = $invoicePdfUrl;

        return $this;
    }

    public function getLocalPath(): ?string
    {
        return $this->localPath;
    }

    public function setLocalPath(?string $localPath): self
    {
        $this->localPath = $localPath;

        return $this;
    }

    public function getDownloadedAt(): ?\DateTimeImmutable
    {
        return $this->downloadedAt;
    }

    public function setDownloadedAt(?\DateTimeImmutable $downloadedAt): self
    {
        $this->downloadedAt = $downloadedAt;

        return $this;
    }

    public function getIssuedAt(): ?\DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(?\DateTimeImmutable $issuedAt): self
    {
        $this->issuedAt = $issuedAt;

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
}
