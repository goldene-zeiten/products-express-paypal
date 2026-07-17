<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\Express\Paypal\Order;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The outcome of capturing an express PayPal order: the PayPal capture status and the capture id that
 * becomes the order's external payment reference. A status other than COMPLETED means the money did not
 * move, so no order must be created.
 */
#[Exclude]
final readonly class PaypalExpressCapture
{
    public function __construct(
        private string $status,
        private string $captureId
    ) {}

    public function isCompleted(): bool
    {
        return $this->status === 'COMPLETED';
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCaptureId(): string
    {
        return $this->captureId;
    }
}
