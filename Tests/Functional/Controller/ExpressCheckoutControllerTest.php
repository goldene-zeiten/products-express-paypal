<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\Express\Paypal\Tests\Functional\Controller;

use GoldeneZeiten\Products\Core\Domain\Model\Order;
use GoldeneZeiten\Products\Core\Domain\Repository\OrderRepository;
use GoldeneZeiten\Products\Core\Service\Order\OrderTokenService;
use GoldeneZeiten\Products\Testing\AbstractFrontendTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Core\Session\SessionManager;
use TYPO3\CMS\Core\Session\UserSession;
use TYPO3\CMS\Frontend\Page\CacheHashCalculator;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * The PayPal express confirm endpoint through the real frontend: the PayPal JS posts the approved order to
 * its page type and gets back the thank-you URL the browser is sent to. The order is taxed in the wallet
 * address' country, and the URL carries the order token a guest needs to see the thank-you page.
 */
final class ExpressCheckoutControllerTest extends AbstractFrontendTestCase
{
    private const SESSION_ID = 'tttttttttttttttttttttttttttttttt';

    private const CONFIRM_PAGE_TYPE = 1784220822;

    protected array $testExtensionsToLoad = [
        'goldene-zeiten/frontend-test',
        'goldene-zeiten/products-api-client',
        'goldene-zeiten/products-payment-paypal',
        'goldene-zeiten/products-express-paypal',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $mockBaseUrl = (string)getenv('MOCK_BASE_URL');
        if ($mockBaseUrl === '') {
            $this->markTestSkipped('MOCK_BASE_URL is not set; the WireMock mock is wired by runTests.sh -s functional.');
        }
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/express_tax_country.csv');
        $this->writeSiteConfiguration(
            'products',
            $this->buildSiteConfiguration(1, additionalRootConfiguration: [
                'dependencies' => $this->siteSetDependencies(),
                'settings' => [
                    'products' => [
                        'pids' => [
                            'checkoutPage' => 2,
                        ],
                        'payment' => [
                            'paypal' => [
                                'environment' => 'sandbox',
                                'clientId' => 'mock-client',
                                'clientSecret' => 'secret',
                                'apiBaseUrl' => $mockBaseUrl . '/express/paypal',
                            ],
                        ],
                    ],
                ],
            ]),
            [$this->buildDefaultLanguageConfiguration('en', '/')]
        );
    }

    protected function siteSetDependencies(): array
    {
        return [
            ...parent::siteSetDependencies(),
            'goldene-zeiten/products-express-paypal',
        ];
    }

    #[Test]
    public function theThankYouUrlCarriesTheOrderTokenForAGuest(): void
    {
        $payload = $this->confirm('FR');

        $order = $this->order();
        $token = $this->get(OrderTokenService::class)->generateToken($order);
        $redirectUrl = urldecode((string)$payload['redirectUrl']);
        $this->assertStringContainsString('tx_productscore_checkout[order]=' . $order->getUid(), $redirectUrl);
        $this->assertStringContainsString('tx_productscore_checkout[hash]=' . $token, $redirectUrl);
    }

    #[Test]
    public function theOrderIsTaxedInTheCountryOfTheWalletAddress(): void
    {
        $this->confirm('FR');

        $order = $this->order();
        $this->assertSame('FR', $order->getTaxCountry());
        $this->assertSame(52, $order->getTotalTax()->getCents());
    }

    /**
     * @return array<string, mixed>
     */
    private function confirm(string $country): array
    {
        $this->get(SessionManager::class)->getSessionBackend('FE')->set(self::SESSION_ID, [
            'ses_iplock' => '[DISABLED]',
            'ses_userid' => 0,
            'ses_data' => serialize([
                'tx_products_basket' => '{"items":[{"productUid":1,"articleUid":null,"quantity":1}]}',
            ]),
        ]);
        $parsedBody = [
            'orderId' => 'PAYPAL-EXPRESS-ORDER-BOOK',
            'email' => 'wallet@example.com',
            'firstName' => 'Wallet',
            'lastName' => 'Buyer',
            'street' => 'Rue 1',
            'postalCode' => '75001',
            'city' => 'Paris',
            'country' => $country,
        ];
        $request = (new InternalRequest('http://localhost/'))
            ->withQueryParameters([
                'id' => '2',
                'type' => (string)self::CONFIRM_PAGE_TYPE,
                'tx_productsexpresspaypal_expresscheckout[action]' => 'confirm',
                'tx_productsexpresspaypal_expresscheckout[controller]' => 'ExpressCheckout',
                'cHash' => $this->get(CacheHashCalculator::class)->generateForParameters('&id=2&tx_productsexpresspaypal_expresscheckout[action]=confirm&tx_productsexpresspaypal_expresscheckout[controller]=ExpressCheckout'),
            ])
            ->withMethod('POST')
            ->withParsedBody($parsedBody)
            ->withBody($this->get(StreamFactoryInterface::class)->createStream(http_build_query($parsedBody)))
            ->withCookieParams(['fe_typo_user' => UserSession::createFromRecord(self::SESSION_ID, [])->getJwt()]);

        $payload = json_decode((string)$this->executeFrontendSubRequest($request)->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('redirectUrl', $payload);

        return $payload;
    }

    private function order(): Order
    {
        $order = $this->get(OrderRepository::class)->findByUid(1);
        $this->assertInstanceOf(Order::class, $order);

        return $order;
    }
}
