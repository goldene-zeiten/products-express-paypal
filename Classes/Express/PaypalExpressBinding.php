<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\Express\Paypal\Express;

use GoldeneZeiten\Products\Core\Domain\Dto\BasketViewModel;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Crypto\HashService;

/**
 * The custom id an express PayPal order is created with and a replayed capture is checked against. It is
 * derived from what is in the basket - not from its price, which varies with the tax country - so it
 * cannot be guessed and ties a PayPal order to the basket it was created for.
 */
#[Autoconfigure(public: true)]
final readonly class PaypalExpressBinding
{
    private const ADDITIONAL_SECRET = 'products-express-paypal-basket';

    public function __construct(
        private HashService $hashService
    ) {}

    public function customIdFor(BasketViewModel $basket): string
    {
        $lines = [];
        foreach ($basket->getItems() as $item) {
            $lines[] = [$item->getProduct()->getUid(), $item->getArticle()?->getUid(), $item->getQuantity()];
        }

        return $this->hashService->hmac(json_encode([$basket->getCurrency(), $lines], JSON_THROW_ON_ERROR), self::ADDITIONAL_SECRET);
    }
}
