<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\Express\Paypal\Express;

use GoldeneZeiten\Products\Core\Configuration\ProductsConfigurationFactory;
use GoldeneZeiten\Products\Core\Domain\Dto\Address;
use GoldeneZeiten\Products\Core\Domain\Dto\BasketViewModel;
use GoldeneZeiten\Products\Core\Domain\Model\Order;
use GoldeneZeiten\Products\Core\Express\ExpressCheckoutProviderInterface;
use GoldeneZeiten\Products\Core\Express\ExpressCheckoutProviderRegistry;
use GoldeneZeiten\Products\Core\Express\ExpressOrderService;
use GoldeneZeiten\Products\Core\Service\Checkout\PriceQuoteService;
use GoldeneZeiten\Products\Core\Shipping\ShippingContextFactory;
use GoldeneZeiten\Products\Core\Shipping\ShippingQuoteService;
use GoldeneZeiten\Products\Express\Paypal\Order\ExpressPaypalOrderClient;
use GoldeneZeiten\Products\Payment\Paypal\Amount\PaypalAmountFormatter;
use GoldeneZeiten\Products\Payment\Paypal\Configuration\PaypalConfigurationFactory;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Orchestrates the three server steps of a PayPal express checkout, all against the shop's own basket and
 * shipping rather than the client's: create the order the buyer approves (for the goods total), keep its
 * amount in step with the order total the core express order service quotes for the address and shipping
 * option the buyer picks, and - at confirm - hand that service the approved PayPal order as a settlement.
 *
 * The core service creates the order first and asks the settlement to set the PayPal order to exactly the
 * order total before capturing it, so what is captured is the total of the order and never a figure the
 * client could have influenced; a capture whose order cannot be placed is refunded.
 */
#[Autoconfigure(public: true)]
final readonly class PaypalExpressCheckoutService
{
    public function __construct(
        private PaypalConfigurationFactory $configurationFactory,
        private ExpressPaypalOrderClient $orderClient,
        private ExpressOrderService $orderService,
        private ExpressCheckoutProviderRegistry $providerRegistry,
        private ShippingQuoteService $shippingQuoteService,
        private ShippingContextFactory $shippingContextFactory,
        private PriceQuoteService $priceQuoteService,
        private ProductsConfigurationFactory $productsConfigurationFactory,
        private PaypalExpressBinding $binding,
        private PaypalAmountFormatter $amountFormatter
    ) {}

    public function createOrder(ServerRequestInterface $request, BasketViewModel $liveBasketViewModel): string
    {
        $quotedBasket = $this->priceQuoteService->resolve($request, $liveBasketViewModel, $this->productsConfigurationFactory->create($request));

        return $this->orderClient->createOrder(
            $quotedBasket->getTotalGross()->getCents(),
            $quotedBasket->getCurrency(),
            $this->binding->customIdFor($liveBasketViewModel),
            $this->configurationFactory->forRequest($request)
        );
    }

    /**
     * Recomputes shipping for the address the buyer picked, sets the PayPal order to the total the core
     * order service quotes for it so the PayPal sheet shows what will be charged, and reports the chosen
     * option back so confirm can place the order with that exact one.
     *
     * @return array{serviceable: bool, shippingOption: string, shippingAmount: int}
     */
    public function quoteShipping(ServerRequestInterface $request, BasketViewModel $liveBasketViewModel, Address $address, string $paypalOrderId): array
    {
        $productsConfiguration = $this->productsConfigurationFactory->create($request);
        $quotedBasket = $this->priceQuoteService->resolve($request, $liveBasketViewModel, $productsConfiguration);
        $context = $this->shippingContextFactory->createFromBasket($quotedBasket, $address, 0, $this->site($request));
        $options = $this->shippingQuoteService->getAvailableOptions($productsConfiguration, $context);
        if ($options === []) {
            return ['serviceable' => false, 'shippingOption' => '', 'shippingAmount' => 0];
        }

        $chosen = $options[0];
        $totals = $this->orderService->quote($request, $this->provider(), $liveBasketViewModel, $address, $chosen->getKey());
        $shippingCents = $totals->getShippingTotal()->getCents();
        $this->orderClient->updateAmount(
            $paypalOrderId,
            $totals->getTotalGross()->getCents() - $shippingCents,
            $shippingCents,
            $quotedBasket->getCurrency(),
            $this->configurationFactory->forRequest($request)
        );

        return ['serviceable' => true, 'shippingOption' => $chosen->getKey(), 'shippingAmount' => $chosen->getCost()->getCents()];
    }

    public function confirm(ServerRequestInterface $request, BasketViewModel $liveBasketViewModel, Address $address, string $shippingOptionKey, string $paypalOrderId): Order
    {
        return $this->orderService->place(
            $request,
            $this->provider(),
            $liveBasketViewModel,
            $address,
            $shippingOptionKey,
            new PaypalExpressSettlement(
                $this->orderClient,
                $this->amountFormatter,
                $this->configurationFactory->forRequest($request),
                $paypalOrderId,
                $this->binding->customIdFor($liveBasketViewModel)
            )
        );
    }

    private function provider(): ExpressCheckoutProviderInterface
    {
        return $this->providerRegistry->get(PaypalExpressCheckoutProvider::IDENTIFIER);
    }

    private function site(ServerRequestInterface $request): ?Site
    {
        $site = $request->getAttribute('site');

        return $site instanceof Site ? $site : null;
    }
}
