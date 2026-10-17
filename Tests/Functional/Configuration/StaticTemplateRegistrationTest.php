<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\Express\Paypal\Tests\Functional\Configuration;

use GoldeneZeiten\Products\Testing\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Installations without site sets include the TypoScript through a sys_template record, whose
 * "Include static" selector stores the value registered here.
 */
final class StaticTemplateRegistrationTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'goldene-zeiten/frontend-test',
        'goldene-zeiten/products-api-client',
        'goldene-zeiten/products-payment-paypal',
        'goldene-zeiten/products-express-paypal',
    ];

    #[Test]
    public function staticTemplateIsSelectableUnderTheExtensionKey(): void
    {
        $values = array_column($GLOBALS['TCA']['sys_template']['columns']['include_static_file']['config']['items'], 'value');

        $this->assertContains('EXT:products_express_paypal/Configuration/TypoScript', $values);
    }
}
