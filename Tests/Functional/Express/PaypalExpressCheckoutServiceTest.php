<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\Express\Paypal\Tests\Functional\Express;

use GoldeneZeiten\Products\Core\Domain\Dto\Address;
use GoldeneZeiten\Products\Core\Domain\Dto\BasketViewItem;
use GoldeneZeiten\Products\Core\Domain\Dto\BasketViewModel;
use GoldeneZeiten\Products\Core\Domain\Enum\PaymentStatus;
use GoldeneZeiten\Products\Core\Domain\Model\Product;
use GoldeneZeiten\Products\Core\Domain\Repository\ProductRepository;
use GoldeneZeiten\Products\Core\Domain\ValueObject\Money;
use GoldeneZeiten\Products\Express\Paypal\Express\Exception\ExpressPaymentDeclinedException;
use GoldeneZeiten\Products\Express\Paypal\Express\PaypalExpressCheckoutService;
use GoldeneZeiten\Products\Express\Paypal\Tests\Functional\AbstractPaypalExpressMockTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * The confirm orchestration end to end: recompute the amount from the shop's basket, patch it onto the
 * approved PayPal order, capture it over the real HTTP path against the mock, and create the paid order
 * through the real core services - so the order recorded is a genuine one attributed to the express
 * provider. It reuses the redirect PayPal method's account configuration.
 */
final class PaypalExpressCheckoutServiceTest extends AbstractPaypalExpressMockTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../../../products-core/Tests/Functional/Service/Order/Fixtures/order_placement.csv');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['products_payment_paypal'] = [
            'environment' => 'sandbox',
            'clientId' => 'mock-client',
            'clientSecret' => 'secret',
            'webhookId' => 'WEBHOOK-OK',
            'brandName' => 'Test Shop',
            'apiBaseUrl' => $this->mockRoot . '/express/paypal',
        ];
    }

    #[Test]
    public function aConfirmedPaymentCapturesAndCreatesThePaidOrder(): void
    {
        $order = $this->get(PaypalExpressCheckoutService::class)->confirm(
            $this->request(),
            $this->basketViewModel($this->product()),
            new Address(email: 'wallet@example.com', firstName: 'Wallet', lastName: 'Buyer', street: 'Wallet St 1', zip: '10115', city: 'Berlin', country: 'DE'),
            '',
            'PAYPAL-EXPRESS-ORDER-1'
        );

        $this->assertSame(PaymentStatus::PAID, $order->getPaymentStatus());
        $this->assertSame('paypal-express', $order->getPaymentMethod());
        $this->assertNotSame('', $order->getOrderNumber());
    }

    #[Test]
    public function aDeclinedOrderCreatesNoOrder(): void
    {
        $this->expectException(ExpressPaymentDeclinedException::class);
        $this->expectExceptionCode(1784220836);

        $this->get(PaypalExpressCheckoutService::class)->confirm(
            $this->request(),
            $this->basketViewModel($this->product()),
            new Address(country: 'DE'),
            '',
            'PAYPAL-EXPRESS-ORDER-DECLINE'
        );
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequest('http://localhost/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    private function product(): Product
    {
        $product = $this->get(ProductRepository::class)->findByUid(1);
        $this->assertInstanceOf(Product::class, $product);

        return $product;
    }

    private function basketViewModel(Product $product): BasketViewModel
    {
        $unitPriceNet = Money::fromDecimalString('84.03');
        $unitPriceGross = Money::fromDecimalString('100.00');
        $item = new BasketViewItem(
            $product,
            null,
            1,
            $unitPriceNet,
            $unitPriceGross,
            0.19,
            $unitPriceNet,
            $unitPriceGross,
            $unitPriceGross->subtract($unitPriceNet)
        );

        return new BasketViewModel([$item], $unitPriceNet, $unitPriceGross, $unitPriceGross->subtract($unitPriceNet), 'EUR');
    }
}
