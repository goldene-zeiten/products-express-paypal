<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\Express\Paypal\Order;

use GoldeneZeiten\Products\Core\Domain\ValueObject\Money;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The outcome of capturing an express PayPal order: the PayPal capture status, the capture id that becomes
 * the order's external payment reference, the amount PayPal reports it moved and the `custom_id` echoed from
 * the created order. A status other than COMPLETED or PENDING means the money did not move, so no order must
 * be created. A capture that was read back because the order had already been captured is marked as replayed:
 * it was not made by this request, so the caller has to prove it belongs to this basket.
 */
#[Exclude]
final readonly class PaypalExpressCapture
{
    public function __construct(
        private string $status,
        private string $captureId,
        private ?Money $amount = null,
        private string $currency = '',
        private string $customId = '',
        private bool $replayed = false
    ) {}

    public function isCompleted(): bool
    {
        return $this->status === 'COMPLETED';
    }

    /**
     * PayPal holds the money for review; the capture settles or is denied later through its webhook.
     */
    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isReplayed(): bool
    {
        return $this->replayed;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCaptureId(): string
    {
        return $this->captureId;
    }

    public function getAmount(): ?Money
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCustomId(): string
    {
        return $this->customId;
    }
}
