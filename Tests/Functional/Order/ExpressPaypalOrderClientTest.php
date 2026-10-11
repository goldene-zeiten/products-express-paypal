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
    public function createsAYenOrderWithoutDecimals(): void
    {
        $this->orderClient()->createOrder(100000, 'JPY', $this->configuration());

        $body = json_decode((string)$this->loggedRequests('/express/paypal/v2/checkout/orders')[0]['body'], true);
        $this->assertSame('1000', $body['purchase_units'][0]['amount']['value']);
        $this->assertSame('1000', $body['purchase_units'][0]['amount']['breakdown']['item_total']['value']);
    }

    /**
     * PayPal validates that the breakdown adds up to the total, so the parts are rounded first and summed.
     */
    #[Test]
    public function patchesAWholeUnitBreakdownThatAddsUpToItsTotal(): void
    {
        $this->orderClient()->updateAmount('PAYPAL-EXPRESS-ORDER-1', 123440, 50040, 'HUF', $this->configuration());

        $patch = json_decode((string)$this->loggedRequests('/express/paypal/v2/checkout/orders/PAYPAL-EXPRESS-ORDER-1', 'PATCH')[0]['body'], true);
        $amount = $patch[0]['value'];
        $this->assertSame('1234', $amount['breakdown']['item_total']['value']);
        $this->assertSame('500', $amount['breakdown']['shipping']['value']);
        $this->assertSame('1734', $amount['value']);
    }

    #[Test]
    public function throwsOnADeclinedCapture(): void
    {
        $this->expectException(PaypalApiException::class);
        $this->expectExceptionCode(1784220834);

        $this->orderClient()->capture('PAYPAL-EXPRESS-ORDER-DECLINE', $this->configuration());
    }
}
