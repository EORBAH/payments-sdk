<?php

declare(strict_types=1);

namespace Payments\Gateways;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Payments\Contracts\PaymentGatewayInterface;
use Payments\Contracts\RefundableInterface;
use Payments\DTO\PaymentRequest;
use Payments\DTO\PaymentResponse;
use Payments\DTO\RefundRequest;
use Payments\Enum\PaymentStatusEnum;
use Payments\Exceptions\PaymentException;

/**
 * Passerelle PayPal basée sur l'API REST officielle "Orders v2".
 *
 * Documentation officielle :
 * - Vue d'ensemble : https://developer.paypal.com/docs/api/orders/v2/
 * - Authentification (client_credentials) : https://developer.paypal.com/api/rest/authentication/
 * - Intégration standard "Checkout" : https://developer.paypal.com/docs/checkout/standard/integrate/
 * - Remboursements (Payments API) : https://developer.paypal.com/docs/api/payments/v2/#captures_refund
 *
 * Flux : initiate() crée une "Order" (intent=CAPTURE) et renvoie le lien
 * "approve" vers lequel rediriger le client. Après retour du client sur
 * returnUrl, l'application doit appeler capture() avec l'orderId pour
 * finaliser le débit (norme PayPal : "Approve then Capture").
 */
final class PayPalGateway implements PaymentGatewayInterface, RefundableInterface
{
    private Client $http;
    private ?string $cachedAccessToken = null;

    /**
     * @param string $clientId
     * @param string $clientSecret
     * @param bool   $sandbox Utiliser l'environnement sandbox.paypal.com (true) ou production api-m.paypal.com (false).
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly bool $sandbox = true,
        ?Client $httpClient = null,
    ) {
        $this->http = $httpClient ?? new Client([
            'base_uri' => $this->baseUri(),
            'timeout' => 30,
        ]);
    }

    public function getName(): string
    {
        return 'paypal';
    }

    private function baseUri(): string
    {
        return $this->sandbox
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    public function initiate(PaymentRequest $request): PaymentResponse
    {
        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $request->orderId,
                'description' => $request->description,
                'amount' => [
                    'currency_code' => $request->currency,
                    'value' => $this->toDecimalAmount($request->amount),
                ],
            ]],
            'application_context' => array_filter([
                'return_url' => $request->returnUrl,
                'cancel_url' => $request->cancelUrl,
                'user_action' => 'PAY_NOW',
            ]),
        ];

        $order = $this->request('POST', '/v2/checkout/orders', $payload);

        $approveLink = null;
        foreach ($order['links'] ?? [] as $link) {
            if (($link['rel'] ?? null) === 'approve') {
                $approveLink = $link['href'];
                break;
            }
        }

        return new PaymentResponse(
            success: true,
            reference: $order['id'],
            status: $this->mapStatus($order['status'] ?? 'CREATED'),
            redirectUrl: $approveLink,
            raw: $order,
        );
    }

    /**
     * Capture une "Order" PayPal préalablement approuvée par le client.
     * À appeler quand le client revient sur votre returnUrl.
     *
     * https://developer.paypal.com/docs/api/orders/v2/#orders_capture
     *
     * @throws PaymentException
     */
    public function capture(string $orderId): PaymentResponse
    {
        $result = $this->request('POST', "/v2/checkout/orders/{$orderId}/capture", []);

        return new PaymentResponse(
            success: ($result['status'] ?? null) === 'COMPLETED',
            reference: $result['id'],
            status: $this->mapStatus($result['status'] ?? 'UNKNOWN'),
            raw: $result,
        );
    }

    public function getStatus(string $reference): PaymentResponse
    {
        $order = $this->request('GET', "/v2/checkout/orders/{$reference}", null);

        return new PaymentResponse(
            success: ($order['status'] ?? null) === 'COMPLETED',
            reference: $order['id'],
            status: $this->mapStatus($order['status'] ?? 'UNKNOWN'),
            raw: $order,
        );
    }

    public function refund(RefundRequest $request): PaymentResponse
    {
        // $request->reference doit être l'ID de la "capture" (pas de l'order).
        // https://developer.paypal.com/docs/api/payments/v2/#captures_refund
        $payload = [];
        if ($request->amount !== null) {
            $payload['amount'] = [
                'value' => $this->toDecimalAmount($request->amount),
                'currency_code' => 'USD', // à ajuster selon la devise réelle de la capture
            ];
        }
        if ($request->reason !== null) {
            $payload['note_to_payer'] = $request->reason;
        }

        $result = $this->request('POST', "/v2/payments/captures/{$request->reference}/refund", $payload);

        return new PaymentResponse(
            success: ($result['status'] ?? null) === 'COMPLETED',
            reference: $result['id'] ?? $request->reference,
            status: $this->mapStatus($result['status'] ?? 'UNKNOWN'),
            raw: $result,
        );
    }

    private function getAccessToken(): string
    {
        if ($this->cachedAccessToken !== null) {
            return $this->cachedAccessToken;
        }

        try {
            $response = $this->http->post('/v1/oauth2/token', [
                'auth' => [$this->clientId, $this->clientSecret],
                'headers' => ['Accept' => 'application/json', 'Accept-Language' => 'en_US'],
                'form_params' => ['grant_type' => 'client_credentials'],
            ]);
        } catch (GuzzleException $e) {
            throw new PaymentException('Échec de l\'authentification PayPal (OAuth2) : ' . $e->getMessage(), [], $e);
        }

        $data = json_decode((string) $response->getBody(), true) ?? [];

        if (!isset($data['access_token'])) {
            throw new PaymentException('Réponse OAuth2 PayPal invalide.', ['response' => $data]);
        }

        return $this->cachedAccessToken = $data['access_token'];
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    private function request(string $method, string $uri, ?array $payload): array
    {
        try {
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                    'Content-Type' => 'application/json',
                ],
            ];
            if ($payload !== null) {
                $options['json'] = $payload;
            }

            $response = $this->http->request($method, $uri, $options);
        } catch (GuzzleException $e) {
            throw new PaymentException("Échec de l'appel PayPal {$method} {$uri} : " . $e->getMessage(), [], $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function toDecimalAmount(int $minorUnitsAmount): string
    {
        return number_format($minorUnitsAmount / 100, 2, '.', '');
    }

    private function mapStatus(string $paypalStatus): PaymentStatusEnum
    {
        return match ($paypalStatus) {
            'COMPLETED' => PaymentStatusEnum::SUCCESS,
            'APPROVED', 'SAVED', 'PAYER_ACTION_REQUIRED' => PaymentStatusEnum::PENDING,
            'CREATED' => PaymentStatusEnum::INITIATED,
            'VOIDED' => PaymentStatusEnum::CANCELLED,
            'DECLINED', 'FAILED' => PaymentStatusEnum::FAILED,
            default => PaymentStatusEnum::UNKNOWN,
        };
    }
}
