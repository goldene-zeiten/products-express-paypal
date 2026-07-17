<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\Express\Paypal\Tests\Functional;

use GoldeneZeiten\Products\ApiClient\Authentication\OAuth2ClientCredentialsProvider;
use GoldeneZeiten\Products\ApiClient\Http\ApiHttpClient;
use GoldeneZeiten\Products\Express\Paypal\Order\ExpressPaypalOrderClient;
use GoldeneZeiten\Products\Payment\Paypal\Authentication\PaypalCredentialsFactory;
use GoldeneZeiten\Products\Payment\Paypal\Configuration\PaypalConfiguration;
use GoldeneZeiten\Products\Payment\Paypal\Configuration\PaypalEnvironment;
use GoldeneZeiten\Products\Testing\AbstractApiMockTestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Base for the PayPal express tests that exercise the real Orders v2 create/patch/capture calls against the
 * shared WireMock mock. The generic WireMock wiring lives in {@see AbstractApiMockTestCase}; this class adds
 * loading the extension (with the redirect PayPal method it reuses), flushing its own token cache, and
 * assembling the express order client pointed at the mock.
 */
abstract class AbstractPaypalExpressMockTestCase extends AbstractApiMockTestCase
{
    protected array $testExtensionsToLoad = [
        'goldene-zeiten/products-api-client',
        'goldene-zeiten/products-payment-paypal',
        'goldene-zeiten/products-express-paypal',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->get(CacheManager::class)->getCache('products_express_paypal_token')->flush();
    }

    protected function configuration(): PaypalConfiguration
    {
        return new PaypalConfiguration(
            PaypalEnvironment::Sandbox,
            'mock-client',
            'secret',
            'WEBHOOK-OK',
            'Test Shop',
            $this->mockRoot . '/express/paypal',
        );
    }

    protected function orderClient(): ExpressPaypalOrderClient
    {
        $apiHttpClient = new ApiHttpClient($this->httpClient());

        return new ExpressPaypalOrderClient(
            $apiHttpClient,
            new OAuth2ClientCredentialsProvider($apiHttpClient, $this->tokenCache()),
            new PaypalCredentialsFactory(),
        );
    }

    protected function tokenCache(): FrontendInterface
    {
        return $this->get(CacheManager::class)->getCache('products_express_paypal_token');
    }
}
