<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\Express\Paypal\Express;

use GoldeneZeiten\Products\Core\Domain\Dto\Payment\PaymentResult;
use GoldeneZeiten\Products\Core\Domain\Enum\PaymentStatus;
use GoldeneZeiten\Products\Core\Domain\Model\Order;
use GoldeneZeiten\Products\Core\Domain\ValueObject\Money;
use GoldeneZeiten\Products\Core\Express\ExpressSettlementInterface;
use GoldeneZeiten\Products\Express\Paypal\Express\Exception\ExpressPaymentDeclinedException;
use GoldeneZeiten\Products\Express\Paypal\Order\ExpressPaypalOrderClient;
use GoldeneZeiten\Products\Express\Paypal\Order\PaypalExpressCapture;
use GoldeneZeiten\Products\Payment\Paypal\Amount\PaypalAmountFormatter;
use GoldeneZeiten\Products\Payment\Paypal\Configuration\PaypalConfiguration;
use GoldeneZeiten\Products\Payment\Paypal\Exception\PaypalApiException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One approved PayPal order, handed to the core express order service by {@see PaypalExpressCheckoutService}:
 * it sets the PayPal order's amount to the order total core computed, captures it, and refunds the capture
 * when the order that amount was meant to pay cannot be placed. A capture PayPal holds for review is
 * accepted as pending; PayPal's capture webhook settles it later.
 *
 * A capture that was only read back because the PayPal order had already been captured is accepted only if
 * it carries this basket's custom id, so a payment made for another basket cannot be claimed.
 */
#[Exclude]
final readonly class PaypalExpressSettlement implements ExpressSettlementInterface
{
    public function __construct(
        private ExpressPaypalOrderClient $orderClient,
        private PaypalAmountFormatter $amountFormatter,
        private PaypalConfiguration $configuration,
        private string $paypalOrderId,
        private string $expectedCustomId
    ) {}

    public function settle(Order $order): PaymentResult
    {
        $capture = $this->capture($order);
        if (!$capture->isCompleted() && !$capture->isPending()) {
            throw new ExpressPaymentDeclinedException(sprintf('PayPal express payment was not captured (status "%s").', $capture->getStatus()), 1784220835);
        }
        if ($capture->isReplayed() && $capture->getCustomId() !== $this->expectedCustomId) {
            throw new ExpressPaymentDeclinedException('The PayPal order was captured for another basket.', 1791724377);
        }

        $settledAmount = $this->settledAmount($capture, $order);

        return $capture->isPending()
            ? PaymentResult::pending($capture->getCaptureId(), settledAmount: $settledAmount)
            : PaymentResult::completed(PaymentStatus::PAID, $capture->getCaptureId(), [], $settledAmount);
    }

    public function refund(PaymentResult $settled): void
    {
        $this->orderClient->refundCapture($settled->getExternalId(), $this->configuration);
    }

    private function capture(Order $order): PaypalExpressCapture
    {
        $shippingCents = $order->getShippingTotal()->getCents();
        try {
            $this->orderClient->updateAmount(
                $this->paypalOrderId,
                $order->getTotalGross()->getCents() - $shippingCents,
                $shippingCents,
                $order->getCurrency(),
                $this->configuration
            );

            return $this->orderClient->capture($this->paypalOrderId, $this->configuration);
        } catch (PaypalApiException $exception) {
            throw new ExpressPaymentDeclinedException('PayPal express settlement failed: ' . $exception->getMessage(), 1784220836, $exception);
        }
    }

    /**
     * What PayPal reports it captured, or null for an amount in another currency or none at all. An amount
     * equal to the order total once rounded the way PayPal does is the order total itself.
     */
    private function settledAmount(PaypalExpressCapture $capture, Order $order): ?Money
    {
        $captured = $capture->getAmount();
        if ($captured === null || strtoupper($capture->getCurrency()) !== strtoupper($order->getCurrency())) {
            return null;
        }

        return $this->amountFormatter->equals($captured, $order->getTotalGross(), $order->getCurrency()) ? $order->getTotalGross() : $captured;
    }
}
