<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\Express\Paypal\Order;

use GoldeneZeiten\Products\ApiClient\Authentication\OAuth2ClientCredentialsProvider;
use GoldeneZeiten\Products\ApiClient\Exception\ApiTransportException;
use GoldeneZeiten\Products\ApiClient\Http\ApiHttpClient;
use GoldeneZeiten\Products\Core\Domain\ValueObject\Money;
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
        private readonly PaypalCredentialsFactory $credentialsFactory
    ) {}

    public function createOrder(int $amountCents, string $currency, PaypalConfiguration $configuration): string
    {
        $response = $this->authorizedRequest('POST', $configuration->baseUrl() . self::ORDERS_PATH, $this->createPayload($amountCents, $currency), $configuration);
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

        return $this->toCapture($this->authorizedRequest('POST', $url, null, $configuration));
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
                default => $payload === null ? $this->httpClient->post($url, $headers) : $this->httpClient->postJson($url, $payload, $headers),
            };
        } catch (ApiTransportException $exception) {
            throw new PaypalApiException('PayPal express request failed at transport level.', 1784220833, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(int $amountCents, string $currency): array
    {
        return [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => 'default',
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
        return [
            'currency_code' => $currency,
            'value' => Money::fromCents($itemTotalCents + $shippingCents)->getDecimalString(),
            'breakdown' => [
                'item_total' => [
                    'currency_code' => $currency,
                    'value' => Money::fromCents($itemTotalCents)->getDecimalString(),
                ],
                'shipping' => [
                    'currency_code' => $currency,
                    'value' => Money::fromCents($shippingCents)->getDecimalString(),
                ],
            ],
        ];
    }

    private function toCapture(ResponseInterface $response): PaypalExpressCapture
    {
        $status = $response->getStatusCode();
        $data = $this->decode($response);
        if ($status === 422 && $this->hasIssue($data, 'ORDER_ALREADY_CAPTURED')) {
            // A replayed confirm: the order was captured on the first pass. Treat it as the success it is.
            return new PaypalExpressCapture('COMPLETED', '');
        }
        if ($status !== 200 && $status !== 201) {
            throw new PaypalApiException(sprintf('PayPal express capture returned HTTP %d.', $status), 1784220834);
        }

        return new PaypalExpressCapture(
            (string)($data['status'] ?? ''),
            (string)($data['purchase_units'][0]['payments']['captures'][0]['id'] ?? '')
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
