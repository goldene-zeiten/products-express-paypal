<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\Express\Paypal\Express;

use GoldeneZeiten\Products\Core\Domain\Dto\Express\ExpressCheckoutContext;
use GoldeneZeiten\Products\Core\Express\ExpressCheckoutProviderInterface;
use GoldeneZeiten\Products\Core\Middleware\ExpressShippingQuoteMiddleware;
use GoldeneZeiten\Products\Payment\Paypal\Configuration\PaypalConfigurationFactory;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * PayPal's Smart Payment Buttons as an express-checkout provider. A single PayPal button on the cart page
 * opens PayPal's own sheet, where the buyer approves with their PayPal address; the shop computes shipping
 * live against that address and captures the order once approved.
 *
 * It reuses the redirect PayPal method's account and configuration (one PayPal account serves both), so a
 * shop that already offers PayPal at checkout only needs to place the express button and nothing else.
 */
final class PaypalExpressCheckoutProvider implements ExpressCheckoutProviderInterface
{
    public const IDENTIFIER = 'paypal-express';

    /**
     * The currencies PayPal settles in. Offering the button for anything else only fails once the sheet
     * opens, so the button is hidden instead.
     */
    private const SUPPORTED_CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY', 'MXN', 'NOK',
        'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB', 'TWD', 'USD',
    ];

    public function __construct(
        private readonly PaypalConfigurationFactory $configurationFactory
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function getLabel(): string
    {
        return (string)LocalizationUtility::translate('express_checkout_paypal', 'ProductsExpressPaypal');
    }

    public function isAvailable(ExpressCheckoutContext $context): bool
    {
        return $this->configurationFactory->forCurrentRequest()->isComplete()
            && in_array(strtoupper($context->getCurrency()), self::SUPPORTED_CURRENCIES, true);
    }

    public function getPriority(): int
    {
        return 40;
    }

    public function getButtonConfiguration(ExpressCheckoutContext $context): array
    {
        return [
            'provider' => self::IDENTIFIER,
            'clientId' => $this->configurationFactory->forCurrentRequest()->clientId,
            'amount' => $context->getAmount()->getCents(),
            'currency' => strtoupper($context->getCurrency()),
            'shippingQuoteUrl' => ExpressShippingQuoteMiddleware::PATH,
        ];
    }
}
