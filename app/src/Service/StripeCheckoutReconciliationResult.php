<?php

namespace App\Service;

use App\Entity\ProjectSubscription;

final class StripeCheckoutReconciliationResult
{
    public const STATUS_NOTHING_TO_CONFIRM = 'nothing_to_confirm';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_ALREADY_CONFIRMED = 'already_confirmed';
    public const STATUS_PENDING = 'pending';
    public const STATUS_MISMATCH = 'mismatch';
    public const STATUS_ERROR = 'error';

    private function __construct(
        public readonly string $status,
        public readonly ?ProjectSubscription $subscription = null,
    ) {
    }

    public static function nothingToConfirm(): self
    {
        return new self(self::STATUS_NOTHING_TO_CONFIRM);
    }

    public static function confirmed(ProjectSubscription $subscription): self
    {
        return new self(self::STATUS_CONFIRMED, $subscription);
    }

    public static function alreadyConfirmed(ProjectSubscription $subscription): self
    {
        return new self(self::STATUS_ALREADY_CONFIRMED, $subscription);
    }

    public static function pending(ProjectSubscription $subscription): self
    {
        return new self(self::STATUS_PENDING, $subscription);
    }

    public static function mismatch(ProjectSubscription $subscription): self
    {
        return new self(self::STATUS_MISMATCH, $subscription);
    }

    public static function error(ProjectSubscription $subscription): self
    {
        return new self(self::STATUS_ERROR, $subscription);
    }

    public function isConfirmed(): bool
    {
        return in_array($this->status, [self::STATUS_CONFIRMED, self::STATUS_ALREADY_CONFIRMED], true);
    }
}
