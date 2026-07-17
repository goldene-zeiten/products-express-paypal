<?php

use GoldeneZeiten\Products\Express\Paypal\Controller\ExpressCheckoutController;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

(static function (): void {
    // OAuth bearer tokens are reused across requests until they expire, so they are cached rather than
    // fetched per API call. Its own cache, separate from the redirect PayPal method, keeps the two
    // independent even though they talk to the same PayPal account.
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['products_express_paypal_token'] ??= [
        'frontend' => VariableFrontend::class,
        'backend' => Typo3DatabaseBackend::class,
        'groups' => [
            'system',
        ],
    ];

    // The button renders the live basket; create/shipping/confirm each drive a PayPal Orders API call, so
    // none may be cached. create, shipping and confirm are additionally dispatched by their own typeNum
    // PAGEs (Configuration/TypoScript/setup.typoscript) so the PayPal JS SDK can post to them and receive
    // raw JSON rather than a page-embedded fragment.
    ExtensionUtility::configurePlugin(
        'ProductsExpressPaypal',
        'ExpressCheckout',
        [
            ExpressCheckoutController::class => 'button, create, shipping, confirm',
        ],
        [
            ExpressCheckoutController::class => 'button, create, shipping, confirm',
        ],
        ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT
    );
})();
