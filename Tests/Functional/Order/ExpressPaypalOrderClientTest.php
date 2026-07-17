<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\Express\Paypal\Tests\Functional\Order;

use GoldeneZeiten\Products\Express\Paypal\Tests\Functional\AbstractPaypalExpressMockTestCase;
use GoldeneZeiten\Products\Payment\Paypal\Exception\PaypalApiException;
use PHPUnit\Framework\Attributes\Test;

/**
 * The express order client's real HTTP path against the mock: create the order for the goods total, patch
 * its amount when shipping is known, and capture it - or surface a decline as the API exception the confirm
 * service turns into a declined response.
 */
final class ExpressPaypalOrderClientTest extends AbstractPaypalExpressMockTestCase
{
    #[Test]
    public function createsAnOrderForTheGoodsTotal(): void
    {
        $orderId = $this->orderClient()->createOrder(10000, 'EUR', $this->configuration());

        $this->assertSame('PAYPAL-EXPRESS-ORDER-1', $orderId);
    }

    #[Test]
    public function patchesTheAmountAndCapturesAnApprovedOrder(): void
    {
        $client = $this->orderClient();
        $configuration = $this->configuration();

        $client->updateAmount('PAYPAL-EXPRESS-ORDER-1', 10000, 2000, 'EUR', $configuration);
        $capture = $client->capture('PAYPAL-EXPRESS-ORDER-1', $configuration);

        $this->assertTrue($capture->isCompleted());
        $this->assertSame('EXPRESS-CAPTURE-1', $capture->getCaptureId());
    }

    #[Test]
    public function throwsOnADeclinedCapture(): void
    {
        $this->expectException(PaypalApiException::class);
        $this->expectExceptionCode(1784220834);

        $this->orderClient()->capture('PAYPAL-EXPRESS-ORDER-DECLINE', $this->configuration());
    }
}
