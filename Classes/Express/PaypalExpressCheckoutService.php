<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\Express\Paypal\Express;

use GoldeneZeiten\Products\Core\Configuration\ProductsConfiguration;
use GoldeneZeiten\Products\Core\Configuration\ProductsConfigurationFactory;
use GoldeneZeiten\Products\Core\Domain\Dto\Address;
use GoldeneZeiten\Products\Core\Domain\Dto\BasketViewModel;
use GoldeneZeiten\Products\Core\Domain\Dto\Payment\PaymentResult;
use GoldeneZeiten\Products\Core\Domain\Enum\PaymentStatus;
use GoldeneZeiten\Products\Core\Domain\Model\Order;
use GoldeneZeiten\Products\Core\Express\ExpressCheckoutProviderRegistry;
use GoldeneZeiten\Products\Core\Express\ExpressOrderService;
use GoldeneZeiten\Products\Core\Service\Checkout\PriceQuoteService;
use GoldeneZeiten\Products\Core\Shipping\ShippingContextFactory;
use GoldeneZeiten\Products\Core\Shipping\ShippingQuoteService;
use GoldeneZeiten\Products\Express\Paypal\Express\Exception\ExpressPaymentDeclinedException;
use GoldeneZeiten\Products\Express\Paypal\Order\ExpressPaypalOrderClient;
use GoldeneZeiten\Products\Express\Paypal\Order\PaypalExpressCapture;
use GoldeneZeiten\Products\Payment\Paypal\Configuration\PaypalConfigurationFactory;
use GoldeneZeiten\Products\Payment\Paypal\Exception\PaypalApiException;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Orchestrates the three server steps of a PayPal express checkout, all against the shop's own basket and
 * shipping rather than the client's: create the order the buyer approves (for the goods total), keep its
 * amount in step with the live shipping the shop quotes for the address the buyer picks, and - only once
 * PayPal has captured the money - create the paid order through the same {@see ExpressOrderService} normal
 * checkout uses.
 *
 * The amount is patched onto the PayPal order once more at confirm, immediately before capture, so what is
 * captured is exactly the server-computed total (goods plus the chosen carrier cost) and never a figure the
 * client could have influenced.
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
        private ProductsConfigurationFactory $productsConfigurationFactory
    ) {}

    public function createOrder(ServerRequestInterface $request, BasketViewModel $liveBasketViewModel): string
    {
        $quotedBasket = $this->quote($request, $liveBasketViewModel);

        return $this->orderClient->createOrder(
            $quotedBasket->getTotalGross()->getCents(),
            $quotedBasket->getCurrency(),
            $this->configurationFactory->forCurrentRequest()
        );
    }

    /**
     * Recomputes shipping for the address the buyer picked, patches the PayPal order so its total (and the
     * PayPal sheet) reflect it, and reports the chosen option back so confirm can charge that exact one.
     *
     * @return array{serviceable: bool, shippingOption: string, shippingAmount: int}
     */
    public function quoteShipping(ServerRequestInterface $request, BasketViewModel $liveBasketViewModel, Address $address, string $paypalOrderId): array
    {
        $productsConfiguration = $this->productsConfigurationFactory->create($request);
        $quotedBasket = $this->priceQuoteService->resolve($request, $liveBasketViewModel, $productsConfiguration);
        $context = $this->shippingContextFactory->createFromBasket($quotedBasket, $address);
        $options = $this->shippingQuoteService->getAvailableOptions($productsConfiguration, $context);
        if ($options === []) {
            return ['serviceable' => false, 'shippingOption' => '', 'shippingAmount' => 0];
        }

        $chosen = $options[0];
        $this->orderClient->updateAmount($paypalOrderId, $quotedBasket->getTotalGross()->getCents(), $chosen->getCost()->getCents(), $quotedBasket->getCurrency(), $this->configurationFactory->forCurrentRequest());

        return ['serviceable' => true, 'shippingOption' => $chosen->getKey(), 'shippingAmount' => $chosen->getCost()->getCents()];
    }

    public function confirm(ServerRequestInterface $request, BasketViewModel $liveBasketViewModel, Address $address, string $shippingOptionKey, string $paypalOrderId): Order
    {
        $productsConfiguration = $this->productsConfigurationFactory->create($request);
        $quotedBasket = $this->priceQuoteService->resolve($request, $liveBasketViewModel, $productsConfiguration);
        $shippingCents = $this->shippingCents($productsConfiguration, $quotedBasket, $address, $shippingOptionKey);
        $capture = $this->settle($paypalOrderId, $quotedBasket->getTotalGross()->getCents(), $shippingCents, $quotedBasket->getCurrency());

        return $this->orderService->place(
            $request,
            $this->providerRegistry->get(PaypalExpressCheckoutProvider::IDENTIFIER),
            $liveBasketViewModel,
            $address,
            $shippingOptionKey,
            PaymentResult::completed(PaymentStatus::PAID, $capture->getCaptureId())
        );
    }

    private function quote(ServerRequestInterface $request, BasketViewModel $liveBasketViewModel): BasketViewModel
    {
        return $this->priceQuoteService->resolve($request, $liveBasketViewModel, $this->productsConfigurationFactory->create($request));
    }

    private function settle(string $paypalOrderId, int $goodsCents, int $shippingCents, string $currency): PaypalExpressCapture
    {
        $configuration = $this->configurationFactory->forCurrentRequest();
        try {
            $this->orderClient->updateAmount($paypalOrderId, $goodsCents, $shippingCents, $currency, $configuration);
            $capture = $this->orderClient->capture($paypalOrderId, $configuration);
        } catch (PaypalApiException $exception) {
            throw new ExpressPaymentDeclinedException('PayPal express settlement failed: ' . $exception->getMessage(), 1784220836, $exception);
        }
        if (!$capture->isCompleted()) {
            throw new ExpressPaymentDeclinedException(sprintf('PayPal express payment was not captured (status "%s").', $capture->getStatus()), 1784220835);
        }

        return $capture;
    }

    private function shippingCents(ProductsConfiguration $configuration, BasketViewModel $basket, Address $address, string $shippingOptionKey): int
    {
        if ($shippingOptionKey === '') {
            return 0;
        }
        $context = $this->shippingContextFactory->createFromBasket($basket, $address);
        foreach ($this->shippingQuoteService->getAvailableOptions($configuration, $context) as $option) {
            if ($option->getKey() === $shippingOptionKey) {
                return $option->getCost()->getCents();
            }
        }

        return 0;
    }
}
