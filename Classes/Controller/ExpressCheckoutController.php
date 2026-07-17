<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\Express\Paypal\Controller;

use GoldeneZeiten\Products\Core\Domain\Dto\Address;
use GoldeneZeiten\Products\Core\Domain\Dto\Express\ExpressCheckoutContext;
use GoldeneZeiten\Products\Core\Domain\Model\Order;
use GoldeneZeiten\Products\Core\Express\ExpressBasketFactory;
use GoldeneZeiten\Products\Core\Express\ExpressBasketTokenService;
use GoldeneZeiten\Products\Core\Express\ExpressCheckoutProviderRegistry;
use GoldeneZeiten\Products\Core\Service\Basket\BasketService;
use GoldeneZeiten\Products\Core\Service\FrontendUserResolver;
use GoldeneZeiten\Products\Express\Paypal\Express\Exception\ExpressPaymentDeclinedException;
use GoldeneZeiten\Products\Express\Paypal\Express\PaypalExpressCheckoutProvider;
use GoldeneZeiten\Products\Express\Paypal\Express\PaypalExpressCheckoutService;
use GoldeneZeiten\Products\Payment\Paypal\Exception\PaypalApiException;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * The frontend seam of the PayPal express checkout. It mirrors PayPal's Smart Button lifecycle:
 * {@see buttonAction} renders the button and hands its JS the signed basket token; {@see createAction}
 * creates the PayPal order the buyer approves; {@see shippingAction} keeps the order (and the PayPal sheet)
 * in step with the shop's live shipping for the picked address; and {@see confirmAction} captures the
 * approved order and creates the paid shop order, answering with the thank-you URL.
 *
 * All three JSON actions recompute against the live session basket, so they run as in-page typeNum PAGE
 * requests with the session and full configuration available, yet return raw JSON to the PayPal JS SDK.
 */
final class ExpressCheckoutController extends ActionController
{
    public function __construct(
        private readonly BasketService $basketService,
        private readonly FrontendUserResolver $frontendUserResolver,
        private readonly ExpressCheckoutProviderRegistry $providerRegistry,
        private readonly ExpressBasketFactory $expressBasketFactory,
        private readonly ExpressBasketTokenService $basketTokenService,
        private readonly PaypalExpressCheckoutService $checkoutService
    ) {}

    public function buttonAction(): ResponseInterface
    {
        $basket = $this->basketService->getBasketViewModel($this->request);
        $frontendUserUid = $this->frontendUserResolver->getUid($this->request);
        $context = new ExpressCheckoutContext($basket->getTotalGross(), $basket->getCurrency(), $frontendUserUid);
        $provider = $this->providerRegistry->get(PaypalExpressCheckoutProvider::IDENTIFIER);
        if ($basket->isEmpty() || !$provider->isAvailable($context)) {
            return $this->htmlResponse();
        }

        $this->view->assignMultiple([
            'configuration' => $provider->getButtonConfiguration($context),
            'basketToken' => $this->basketTokenService->issue($this->expressBasketFactory->createFromBasket($basket, $frontendUserUid)),
        ]);

        return $this->htmlResponse();
    }

    public function createAction(): ResponseInterface
    {
        $basket = $this->basketService->getBasketViewModel($this->request);
        if ($basket->isEmpty()) {
            return new JsonResponse(['error' => 'empty_basket'], 400);
        }
        try {
            $orderId = $this->checkoutService->createOrder($this->request, $basket);
        } catch (PaypalApiException) {
            return new JsonResponse(['error' => 'create_failed'], 502);
        }

        return new JsonResponse(['orderId' => $orderId]);
    }

    public function shippingAction(): ResponseInterface
    {
        $body = $this->parsedBody();
        $address = new Address(
            zip: (string)($body['postalCode'] ?? ''),
            country: (string)($body['country'] ?? ''),
            state: (string)($body['state'] ?? '')
        );
        try {
            $quote = $this->checkoutService->quoteShipping($this->request, $this->basketService->getBasketViewModel($this->request), $address, (string)($body['orderId'] ?? ''));
        } catch (PaypalApiException) {
            return new JsonResponse(['error' => 'shipping_failed'], 502);
        }

        return new JsonResponse($quote);
    }

    public function confirmAction(): ResponseInterface
    {
        $body = $this->parsedBody();
        try {
            $order = $this->checkoutService->confirm(
                $this->request,
                $this->basketService->getBasketViewModel($this->request),
                $this->buildAddress($body),
                (string)($body['shippingOption'] ?? ''),
                (string)($body['orderId'] ?? '')
            );
        } catch (ExpressPaymentDeclinedException) {
            return new JsonResponse(['error' => 'payment_declined'], 402);
        }

        return new JsonResponse(['redirectUrl' => $this->thankYouUrl($order)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function parsedBody(): array
    {
        $body = $this->request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function buildAddress(array $body): Address
    {
        return new Address(
            email: (string)($body['email'] ?? ''),
            firstName: (string)($body['firstName'] ?? ''),
            lastName: (string)($body['lastName'] ?? ''),
            company: (string)($body['company'] ?? ''),
            street: (string)($body['street'] ?? ''),
            zip: (string)($body['postalCode'] ?? ''),
            city: (string)($body['city'] ?? ''),
            country: (string)($body['country'] ?? ''),
            state: (string)($body['state'] ?? '')
        );
    }

    private function thankYouUrl(Order $order): string
    {
        $site = $this->request->getAttribute('site');
        $checkoutPageUid = $site instanceof Site ? (int)$site->getSettings()->get('products.pids.checkoutPage') : 0;

        return $this->uriBuilder->reset()
            ->setCreateAbsoluteUri(true)
            ->setTargetPageUid($checkoutPageUid)
            ->uriFor('thankYou', ['order' => $order->getUid()], 'Checkout', 'ProductsCore', 'Checkout');
    }
}
