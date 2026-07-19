<?php

namespace App\Service;

use App\Entity\ProjectSubscription;

final class StripePendingCheckoutInspection
{
    public const STATUS_NONE = 'none';
    public const STATUS_OPEN = 'open';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_MISSING = 'missing';
    public const STATUS_PAID = 'paid';
    public const STATUS_ERROR = 'error';

    private function __construct(
        public readonly string $status,
        public readonly ?ProjectSubscription $subscription = null,
        private readonly ?string $checkoutUrl = null,
        private readonly ?string $sessionStatus = null,
        private readonly ?string $paymentStatus = null,
    ) {
    }

    public static function none(): self
    {
        return new self(self::STATUS_NONE);
    }

    public static function open(
        ProjectSubscription $subscription,
        string $checkoutUrl,
        ?string $sessionStatus = null,
        ?string $paymentStatus = null
    ): self {
        return new self(self::STATUS_OPEN, $subscription, $checkoutUrl, $sessionStatus, $paymentStatus);
    }

    public static function expired(ProjectSubscription $subscription, ?string $sessionStatus = null, ?string $paymentStatus = null): self
    {
        return new self(self::STATUS_EXPIRED, $subscription, null, $sessionStatus, $paymentStatus);
    }

    public static function missing(ProjectSubscription $subscription, ?string $sessionStatus = null, ?string $paymentStatus = null): self
    {
        return new self(self::STATUS_MISSING, $subscription, null, $sessionStatus, $paymentStatus);
    }

    public static function paid(ProjectSubscription $subscription, ?string $sessionStatus = null, ?string $paymentStatus = null): self
    {
        return new self(self::STATUS_PAID, $subscription, null, $sessionStatus, $paymentStatus);
    }

    public static function error(ProjectSubscription $subscription, ?string $sessionStatus = null, ?string $paymentStatus = null): self
    {
        return new self(self::STATUS_ERROR, $subscription, null, $sessionStatus, $paymentStatus);
    }

    public function hasPendingCheckout(): bool
    {
        return $this->status !== self::STATUS_NONE;
    }

    public function isReusable(): bool
    {
        return $this->status === self::STATUS_OPEN && $this->checkoutUrl !== null;
    }

    public function shouldRetry(): bool
    {
        return in_array($this->status, [self::STATUS_EXPIRED, self::STATUS_MISSING, self::STATUS_ERROR], true);
    }

    public function shouldVerify(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function getCheckoutUrl(): ?string
    {
        return $this->checkoutUrl;
    }

    public function getSessionStatus(): ?string
    {
        return $this->sessionStatus;
    }

    public function getPaymentStatus(): ?string
    {
        return $this->paymentStatus;
    }
}
