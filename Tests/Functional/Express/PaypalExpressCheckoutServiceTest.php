<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\Express\Paypal\Tests\Functional\Express;

use GoldeneZeiten\Products\Core\Domain\Dto\Address;
use GoldeneZeiten\Products\Core\Domain\Dto\BasketViewItem;
use GoldeneZeiten\Products\Core\Domain\Dto\BasketViewModel;
use GoldeneZeiten\Products\Core\Domain\Enum\OrderStatus;
use GoldeneZeiten\Products\Core\Domain\Enum\PaymentStatus;
use GoldeneZeiten\Products\Core\Domain\Model\Product;
use GoldeneZeiten\Products\Core\Domain\Repository\OrderRepository;
use GoldeneZeiten\Products\Core\Domain\Repository\PaymentTransactionRepository;
use GoldeneZeiten\Products\Core\Domain\Repository\ProductRepository;
use GoldeneZeiten\Products\Core\Domain\ValueObject\Money;
use GoldeneZeiten\Products\Core\Express\Exception\ExpressAmountMismatchException;
use GoldeneZeiten\Products\Core\Express\Exception\ExpressOrderFailedException;
use GoldeneZeiten\Products\Core\Payment\PaymentCallbackService;
use GoldeneZeiten\Products\Core\Shipping\Exception\MissingShippingOptionException;
use GoldeneZeiten\Products\Express\Paypal\Express\Exception\ExpressPaymentDeclinedException;
use GoldeneZeiten\Products\Express\Paypal\Express\PaypalExpressBinding;
use GoldeneZeiten\Products\Express\Paypal\Express\PaypalExpressCheckoutService;
use GoldeneZeiten\Products\Express\Paypal\Tests\Functional\AbstractPaypalExpressMockTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\Entity\Site;

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
        $this->importCSVDataSet(__DIR__ . '/../../../../products-core/Tests/Functional/Express/Fixtures/express_with_fees.csv');
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

    #[Test]
    public function thePayPalOrderIsSetToTheFullOrderTotalBeforeItIsCaptured(): void
    {
        $order = $this->get(PaypalExpressCheckoutService::class)->confirm(
            $this->request(shipping: true),
            $this->basketViewModel($this->product()),
            $this->address(),
            'tablerate:1',
            'PAYPAL-EXPRESS-ORDER-FEES'
        );

        // 100.00 goods + 5.00 shipping + 3.00 handling fee, not the 105.00 goods-plus-carrier figure.
        $patch = json_decode((string)$this->loggedRequests('/express/paypal/v2/checkout/orders/PAYPAL-EXPRESS-ORDER-FEES', 'PATCH')[0]['body'], true);
        $this->assertSame('108.00', $patch[0]['value']['value']);
        $this->assertSame(10800, $order->getTotalGross()->getCents());
        $this->assertSame(PaymentStatus::PAID, $order->getPaymentStatus());
    }

    #[Test]
    public function aMissingShippingOptionIsRejectedBeforeAnythingIsCaptured(): void
    {
        try {
            $this->get(PaypalExpressCheckoutService::class)->confirm(
                $this->request(shipping: true),
                $this->basketViewModel($this->product()),
                $this->address(),
                '',
                'PAYPAL-EXPRESS-ORDER-FEES'
            );
            $this->fail('Expected ExpressOrderFailedException was not thrown.');
        } catch (ExpressOrderFailedException $exception) {
            $this->assertInstanceOf(MissingShippingOptionException::class, $exception->getPrevious());
        }

        $this->assertSame([], $this->loggedRequests('/express/paypal/v2/checkout/orders/PAYPAL-EXPRESS-ORDER-FEES/capture'));
    }

    #[Test]
    public function aCaptureOfADifferentAmountIsRefunded(): void
    {
        try {
            $this->get(PaypalExpressCheckoutService::class)->confirm(
                $this->request(),
                $this->basketViewModel($this->product()),
                $this->address(),
                '',
                'PAYPAL-EXPRESS-ORDER-UNDERPAID'
            );
            $this->fail('Expected ExpressAmountMismatchException was not thrown.');
        } catch (ExpressAmountMismatchException) {
            // expected
        }

        $this->assertCount(1, $this->loggedRequests('/express/paypal/v2/payments/captures/EXPRESS-CAPTURE-UNDERPAID/refund'));
    }

    /**
     * An order captured for somebody else's basket must not buy this one - and is not ours to refund.
     */
    #[Test]
    public function anAlreadyCapturedOrderOfAnotherBasketIsDeclinedAndNotRefunded(): void
    {
        try {
            $this->get(PaypalExpressCheckoutService::class)->confirm(
                $this->request(),
                $this->basketViewModel($this->product()),
                $this->address(),
                '',
                'PAYPAL-EXPRESS-ORDER-CAPTURED'
            );
            $this->fail('Expected ExpressPaymentDeclinedException was not thrown.');
        } catch (ExpressPaymentDeclinedException $exception) {
            $this->assertSame(1791724377, $exception->getCode());
        }

        $this->assertSame([], $this->loggedRequests('/express/paypal/v2/payments/captures/EXPRESS-CAPTURE-OTHER/refund'));
    }

    #[Test]
    public function anAlreadyCapturedOrderOfThisBasketIsAccepted(): void
    {
        $basket = $this->basketViewModel($this->product());
        $customId = $this->get(PaypalExpressBinding::class)->customIdFor($basket);
        $this->send('POST', $this->mockRoot . '/__admin/mappings', [
            'request' => ['method' => 'POST', 'urlPath' => '/express/paypal/v2/checkout/orders/PAYPAL-EXPRESS-ORDER-REPLAY/capture'],
            'response' => ['status' => 422, 'jsonBody' => ['details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]]],
        ]);
        $this->send('POST', $this->mockRoot . '/__admin/mappings', [
            'request' => ['method' => 'GET', 'urlPath' => '/express/paypal/v2/checkout/orders/PAYPAL-EXPRESS-ORDER-REPLAY'],
            'response' => ['status' => 200, 'jsonBody' => [
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'custom_id' => $customId,
                    'payments' => ['captures' => [['id' => 'EXPRESS-CAPTURE-REPLAY', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '100.00']]]],
                ]],
            ]],
        ]);

        $order = $this->get(PaypalExpressCheckoutService::class)->confirm($this->request(), $basket, $this->address(), '', 'PAYPAL-EXPRESS-ORDER-REPLAY');

        $this->assertSame(PaymentStatus::PAID, $order->getPaymentStatus());
    }

    /**
     * Express callbacks may run before core publishes the current request, so credentials configured only
     * on the site must be read from the site of the request the service is handed.
     */
    #[Test]
    public function siteOnlyCredentialsSettleTheExpressPayment(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['products_payment_paypal'] = [];
        $request = $this->request()->withAttribute('site', new Site('products', 1, [
            'base' => 'http://localhost/',
            'rootPageId' => 1,
            'settings' => [
                'products' => [
                    'payment' => [
                        'paypal' => [
                            'environment' => 'sandbox',
                            'clientId' => 'mock-client',
                            'clientSecret' => 'secret',
                            'apiBaseUrl' => $this->mockRoot . '/express/paypal',
                        ],
                    ],
                ],
            ],
        ]));

        $order = $this->get(PaypalExpressCheckoutService::class)->confirm(
            $request,
            $this->basketViewModel($this->product()),
            new Address(email: 'wallet@example.com', firstName: 'Wallet', lastName: 'Buyer', street: 'Wallet St 1', zip: '10115', city: 'Berlin', country: 'DE'),
            '',
            'PAYPAL-EXPRESS-ORDER-1'
        );

        $this->assertSame(PaymentStatus::PAID, $order->getPaymentStatus());
    }

    #[Test]
    public function aCaptureThatPayPalHoldsAcceptsTheOrderAsPendingAndBindsTheCapture(): void
    {
        $order = $this->get(PaypalExpressCheckoutService::class)->confirm(
            $this->request(),
            $this->basketViewModel($this->product()),
            $this->address(),
            '',
            'PAYPAL-EXPRESS-ORDER-PENDING'
        );

        $this->assertSame(PaymentStatus::PENDING, $order->getPaymentStatus());
        $this->assertSame(OrderStatus::CONFIRMED, $order->getStatus());
        $this->assertSame([], $this->loggedRequests('/express/paypal/v2/payments/captures/EXPRESS-CAPTURE-PENDING/refund'));
        $transaction = $this->get(PaymentTransactionRepository::class)->findOneByExternalId('paypal-express', 'EXPRESS-CAPTURE-PENDING');
        $this->assertSame($order->getUid(), $transaction?->getOrderUid());
    }

    #[Test]
    public function thePayPalWebhookSettlesAHeldExpressOrderOnceTheCaptureCompletes(): void
    {
        $order = $this->get(PaypalExpressCheckoutService::class)->confirm(
            $this->request(),
            $this->basketViewModel($this->product()),
            $this->address(),
            '',
            'PAYPAL-EXPRESS-ORDER-PENDING'
        );

        $this->get(PaymentCallbackService::class)->handleStaticWebhook('paypal', $this->captureEvent('PAYMENT.CAPTURE.COMPLETED', '100.00'));

        $this->assertSame(PaymentStatus::PAID, $this->get(OrderRepository::class)->findByUid((int)$order->getUid())?->getPaymentStatus());
    }

    #[Test]
    public function thePayPalWebhookCancelsAHeldExpressOrderWhenTheCaptureIsDenied(): void
    {
        $order = $this->get(PaypalExpressCheckoutService::class)->confirm(
            $this->request(),
            $this->basketViewModel($this->product()),
            $this->address(),
            '',
            'PAYPAL-EXPRESS-ORDER-PENDING'
        );

        $this->get(PaymentCallbackService::class)->handleStaticWebhook('paypal', $this->captureEvent('PAYMENT.CAPTURE.DENIED', '100.00'));

        $reloaded = $this->get(OrderRepository::class)->findByUid((int)$order->getUid());
        $this->assertSame(PaymentStatus::FAILED, $reloaded?->getPaymentStatus());
        $this->assertSame(OrderStatus::CANCELLED, $reloaded->getStatus());
    }

    private function captureEvent(string $type, string $amount): ServerRequestInterface
    {
        $payload = json_encode([
            'event_type' => $type,
            'resource' => ['id' => 'EXPRESS-CAPTURE-PENDING', 'custom_id' => 'a-basket-hash', 'amount' => ['value' => $amount, 'currency_code' => 'EUR']],
        ], JSON_THROW_ON_ERROR);
        $stream = new Stream('php://temp', 'rw');
        $stream->write($payload);
        $stream->rewind();

        return new ServerRequest('http://localhost/webhook', 'POST', $stream, [
            'PayPal-Transmission-Id' => 'txn-1',
            'PayPal-Transmission-Time' => '2026-07-15T12:00:00Z',
            'PayPal-Transmission-Sig' => 'signature',
            'PayPal-Cert-Url' => 'https://api.paypal.com/cert.pem',
            'PayPal-Auth-Algo' => 'SHA256withRSA',
        ]);
    }

    private function request(bool $shipping = false): ServerRequestInterface
    {
        $site = new Site('products', 1, ['settings' => ['products' => [
            'shipping' => ['enabled' => $shipping],
            'handling' => ['enabled' => $shipping],
        ]]]);

        return (new ServerRequest('http://localhost/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('site', $site);
    }

    private function address(): Address
    {
        return new Address(email: 'wallet@example.com', firstName: 'Wallet', lastName: 'Buyer', street: 'Wallet St 1', zip: '10115', city: 'Berlin', country: 'DE');
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
