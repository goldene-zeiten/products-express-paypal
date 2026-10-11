<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\Express\Paypal\Order;

use GoldeneZeiten\Products\ApiClient\Authentication\OAuth2ClientCredentialsProvider;
use GoldeneZeiten\Products\ApiClient\Exception\ApiTransportException;
use GoldeneZeiten\Products\ApiClient\Http\ApiHttpClient;
use GoldeneZeiten\Products\Core\Domain\ValueObject\Money;
use GoldeneZeiten\Products\Payment\Paypal\Amount\PaypalAmountFormatter;
use GoldeneZeiten\Products\Payment\Paypal\Authentication\PaypalCredentialsFactory;
use GoldeneZeiten\Products\Payment\Paypal\Configuration\PaypalConfiguration;
use GoldeneZeiten\Products\Payment\Paypal\Exception\PaypalApiException;
use Psr\Http\Message\ResponseInterface;

/**
 * Drives the PayPal Orders v2 API for the express flow: create the order the buyer approves in the PayPal
 * sheet, keep its amount in step with the live shipping the shop computes (the express counterpart of the
 * redirect method never patches, because its amount is fixed once the order exists), and capture the money
 * once approved. It reuses the shared api-client HTTP + OAuth stack and PayPal's own credentials/config, so
 * the express and redirect PayPal integrations share one account without sharing state.
 */
final class ExpressPaypalOrderClient
{
    private const ORDERS_PATH = '/v2/checkout/orders';

    public function __construct(
        private readonly ApiHttpClient $httpClient,
        private readonly OAuth2ClientCredentialsProvider $tokenProvider,
        private readonly PaypalCredentialsFactory $credentialsFactory,
        private readonly PaypalAmountFormatter $amountFormatter
    ) {}

    /**
     * @param string $customId echoed back on the capture, so a capture read back later can be proven to belong to the basket
     */
    public function createOrder(int $amountCents, string $currency, string $customId, PaypalConfiguration $configuration): string
    {
        $response = $this->authorizedRequest('POST', $configuration->baseUrl() . self::ORDERS_PATH, $this->createPayload($amountCents, $currency, $customId), $configuration);
        $status = $response->getStatusCode();
        $orderId = (string)($this->decode($response)['id'] ?? '');
        if (($status !== 200 && $status !== 201) || $orderId === '') {
            throw new PaypalApiException(sprintf('PayPal express order creation returned HTTP %d.', $status), 1784220831);
        }

        return $orderId;
    }

    public function updateAmount(string $orderId, int $itemTotalCents, int $shippingCents, string $currency, PaypalConfiguration $configuration): void
    {
        $url = $configuration->baseUrl() . self::ORDERS_PATH . '/' . rawurlencode($orderId);
        $response = $this->authorizedRequest('PATCH', $url, $this->amountPatch($itemTotalCents, $shippingCents, $currency), $configuration);
        if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 204) {
            throw new PaypalApiException(sprintf('PayPal express order patch returned HTTP %d.', $response->getStatusCode()), 1784220832);
        }
    }

    public function capture(string $orderId, PaypalConfiguration $configuration): PaypalExpressCapture
    {
        $url = $configuration->baseUrl() . self::ORDERS_PATH . '/' . rawurlencode($orderId) . '/capture';

        $response = $this->authorizedRequest('POST', $url, null, $configuration);
        if ($response->getStatusCode() === 422 && $this->hasIssue($this->decode($response), 'ORDER_ALREADY_CAPTURED')) {
            // A replayed confirm: the 422 body carries no capture details, so read the order back. The
            // caller must prove the capture belongs to its basket before it trusts it.
            return $this->show($orderId, $configuration);
        }

        return $this->toCapture($response, false);
    }

    /**
     * Gives a captured payment back in full, for an express order that could not be placed.
     */
    public function refundCapture(string $captureId, PaypalConfiguration $configuration): void
    {
        $url = $configuration->baseUrl() . '/v2/payments/captures/' . rawurlencode($captureId) . '/refund';
        $response = $this->authorizedRequest('POST', $url, null, $configuration);
        if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 201) {
            throw new PaypalApiException(sprintf('PayPal express refund returned HTTP %d.', $response->getStatusCode()), 1791724353);
        }
    }

    private function show(string $orderId, PaypalConfiguration $configuration): PaypalExpressCapture
    {
        $url = $configuration->baseUrl() . self::ORDERS_PATH . '/' . rawurlencode($orderId);
        $response = $this->authorizedRequest('GET', $url, null, $configuration);
        if ($response->getStatusCode() !== 200) {
            throw new PaypalApiException(sprintf('PayPal express order lookup returned HTTP %d.', $response->getStatusCode()), 1791724349);
        }

        return $this->toCapture($response, true);
    }

    /**
     * @param array<int|string, mixed>|null $payload null sends an empty body (capture takes its input from the URL)
     */
    private function authorizedRequest(string $method, string $url, ?array $payload, PaypalConfiguration $configuration): ResponseInterface
    {
        $credentials = $this->credentialsFactory->forConfiguration($configuration);
        $response = $this->send($method, $url, $payload, $this->tokenProvider->getToken($credentials));
        if ($response->getStatusCode() === 401) {
            // The cached token was rejected (PayPal can revoke early); retry once with a fresh one.
            $response = $this->send($method, $url, $payload, $this->tokenProvider->getToken($credentials, true));
        }

        return $response;
    }

    /**
     * @param array<int|string, mixed>|null $payload
     */
    private function send(string $method, string $url, ?array $payload, string $token): ResponseInterface
    {
        $headers = [
            'Authorization' => 'Bearer ' . $token,
        ];

        try {
            return match ($method) {
                'PATCH' => $this->httpClient->patchJson($url, (array)$payload, $headers),
                'GET' => $this->httpClient->get($url, $headers),
                default => $payload === null ? $this->httpClient->post($url, $headers) : $this->httpClient->postJson($url, $payload, $headers),
            };
        } catch (ApiTransportException $exception) {
            throw new PaypalApiException('PayPal express request failed at transport level.', 1784220833, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(int $amountCents, string $currency, string $customId): array
    {
        return [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => 'default',
                    'custom_id' => $customId,
                    'amount' => $this->amountBreakdown($amountCents, 0, $currency),
                ],
            ],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'shipping_preference' => 'GET_FROM_FILE',
                        'user_action' => 'PAY_NOW',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function amountPatch(int $itemTotalCents, int $shippingCents, string $currency): array
    {
        return [
            [
                'op' => 'replace',
                'path' => "/purchase_units/@reference_id=='default'/amount",
                'value' => $this->amountBreakdown($itemTotalCents, $shippingCents, $currency),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function amountBreakdown(int $itemTotalCents, int $shippingCents, string $currency): array
    {
        // The parts are rounded before they are summed, so the breakdown always adds up to the total.
        $itemTotal = $this->amountFormatter->toUnits(Money::fromCents($itemTotalCents), $currency);
        $shipping = $this->amountFormatter->toUnits(Money::fromCents($shippingCents), $currency);

        return [
            'currency_code' => $currency,
            'value' => $this->amountFormatter->formatUnits($itemTotal + $shipping, $currency),
            'breakdown' => [
                'item_total' => [
                    'currency_code' => $currency,
                    'value' => $this->amountFormatter->formatUnits($itemTotal, $currency),
                ],
                'shipping' => [
                    'currency_code' => $currency,
                    'value' => $this->amountFormatter->formatUnits($shipping, $currency),
                ],
            ],
        ];
    }

    private function toCapture(ResponseInterface $response, bool $replayed): PaypalExpressCapture
    {
        $status = $response->getStatusCode();
        if (!$replayed && $status !== 200 && $status !== 201) {
            throw new PaypalApiException(sprintf('PayPal express capture returned HTTP %d.', $status), 1784220834);
        }
        $data = $this->decode($response);
        $purchaseUnit = (array)($data['purchase_units'][0] ?? []);
        $capture = (array)($purchaseUnit['payments']['captures'][0] ?? []);
        $amount = (array)($capture['amount'] ?? []);

        return new PaypalExpressCapture(
            (string)($capture['status'] ?? $data['status'] ?? ''),
            (string)($capture['id'] ?? ''),
            isset($amount['value']) ? Money::fromDecimalString((string)$amount['value']) : null,
            (string)($amount['currency_code'] ?? ''),
            (string)($capture['custom_id'] ?? $purchaseUnit['custom_id'] ?? ''),
            $replayed
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $data = json_decode((string)$response->getBody(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hasIssue(array $data, string $issue): bool
    {
        foreach ((array)($data['details'] ?? []) as $detail) {
            if (is_array($detail) && (string)($detail['issue'] ?? '') === $issue) {
                return true;
            }
        }

        return false;
    }
}
