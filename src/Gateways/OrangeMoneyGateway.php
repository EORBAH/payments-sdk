<?php

declare(strict_types=1);

namespace Payments\Gateways;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Payments\Contracts\PaymentGatewayInterface;
use Payments\DTO\PaymentRequest;
use Payments\DTO\PaymentResponse;
use Payments\Enum\PaymentStatusEnum;
use Payments\Exceptions\PaymentException;

/**
 * Passerelle Orange Money — "Orange Money Web Payment / M Payment API",
 * l'API officielle du Orange Developer Portal, disponible en Guinée
 * (code pays "gn") entre autres pays d'Afrique de l'Ouest/Centrale.
 *
 * Documentation officielle :
 * - Portail / souscription : https://developer.orange.com/apis/om-webpay
 * - FAQ : https://developer.orange.com/apis/om-webpay/faq
 *
 * Flux (3 étapes) :
 * 1. OAuth2 client_credentials sur https://api.orange.com/oauth/v3/token
 *    (Authorization: Basic base64(client_id:client_secret)) pour obtenir un access_token.
 * 2. POST https://api.orange.com/orange-money-webpay/{env}/v1/webpayment
 *    avec le merchant_key + détails de la commande → reçoit payment_url + pay_token.
 *    Le client est redirigé sur payment_url pour valider via OTP USSD.
 * 3. POST .../v1/transactionstatus avec order_id + amount + pay_token pour connaître le statut.
 *
 * $env vaut "gn" pour la Guinée en production, ou "dev" pour le bac à sable
 * (voir doc Orange Developer / SDK communautaires tels que om4j).
 */
final class OrangeMoneyGateway implements PaymentGatewayInterface
{
    private Client $http;
    private ?string $cachedAccessToken = null;

    /**
     * @param string $clientId     Consumer Key OAuth (Orange Developer Portal).
     * @param string $clientSecret Consumer Secret OAuth.
     * @param string $merchantKey  Clé marchande Orange Money (fournie lors de la souscription Web Payment).
     * @param string $env          Code environnement/pays Orange Money : "gn" (Guinée prod) ou "dev" (sandbox).
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $merchantKey,
        private readonly string $env = 'gn',
        ?Client $httpClient = null,
    ) {
        $this->http = $httpClient ?? new Client([
            'base_uri' => 'https://api.orange.com',
            'timeout' => 30,
        ]);
    }

    public function getName(): string
    {
        return 'orange_money';
    }

    public function initiate(PaymentRequest $request): PaymentResponse
    {
        if ($request->notifyUrl === null || $request->returnUrl === null || $request->cancelUrl === null) {
            throw new PaymentException(
                'Orange Money exige returnUrl, cancelUrl et notifyUrl dans la PaymentRequest.'
            );
        }

        $payload = [
            'merchant_key' => $this->merchantKey,
            'currency' => $request->currency,
            'order_id' => $request->orderId,
            'amount' => $request->amount,
            'return_url' => $request->returnUrl,
            'cancel_url' => $request->cancelUrl,
            'notif_url' => $request->notifyUrl,
            'lang' => $request->metadata['lang'] ?? 'fr',
            'reference' => $request->description ?? $request->orderId,
        ];

        $result = $this->request('POST', "/orange-money-webpay/{$this->env}/v1/webpayment", $payload);

        if (!isset($result['pay_token'], $result['payment_url'])) {
            throw new PaymentException('Réponse Orange Money inattendue lors de la création du paiement.', ['response' => $result]);
        }

        return new PaymentResponse(
            success: true,
            reference: $result['pay_token'],
            status: PaymentStatusEnum::INITIATED,
            redirectUrl: $result['payment_url'],
            raw: $result,
        );
    }

    /**
     * @param string $reference pay_token renvoyé par initiate(). Nécessite aussi order_id + amount
     *                          d'origine : passez-les via le 2e/3e paramètre si vous ne les stockez pas ailleurs.
     */
    public function getStatus(string $reference, ?string $orderId = null, ?int $amount = null): PaymentResponse
    {
        if ($orderId === null || $amount === null) {
            throw new PaymentException(
                "L'API Orange Money exige order_id et amount en plus du pay_token pour vérifier le statut. " .
                'Appelez getStatus($payToken, $orderId, $amount).'
            );
        }

        $result = $this->request('POST', "/orange-money-webpay/{$this->env}/v1/transactionstatus", [
            'order_id' => $orderId,
            'amount' => $amount,
            'pay_token' => $reference,
        ]);

        $status = $this->mapStatus($result['status'] ?? null);

        return new PaymentResponse(
            success: $status === PaymentStatusEnum::SUCCESS,
            reference: $reference,
            status: $status,
            raw: $result,
        );
    }

    private function getAccessToken(): string
    {
        if ($this->cachedAccessToken !== null) {
            return $this->cachedAccessToken;
        }

        try {
            $response = $this->http->post('/oauth/v3/token', [
                'auth' => [$this->clientId, $this->clientSecret],
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'form_params' => ['grant_type' => 'client_credentials'],
            ]);
        } catch (GuzzleException $e) {
            throw new PaymentException("Échec de l'authentification OAuth2 Orange Money : " . $e->getMessage(), [], $e);
        }

        $data = json_decode((string) $response->getBody(), true) ?? [];

        if (!isset($data['access_token'])) {
            throw new PaymentException('Réponse OAuth2 Orange Money invalide.', ['response' => $data]);
        }

        return $this->cachedAccessToken = $data['access_token'];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request(string $method, string $uri, array $payload): array
    {
        try {
            $response = $this->http->request($method, $uri, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new PaymentException("Échec de l'appel Orange Money {$method} {$uri} : " . $e->getMessage(), [], $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function mapStatus(?string $omStatus): PaymentStatusEnum
    {
        return match ($omStatus) {
            'SUCCESS' => PaymentStatusEnum::SUCCESS,
            'PENDING' => PaymentStatusEnum::PENDING,
            'INITIATED' => PaymentStatusEnum::INITIATED,
            'EXPIRED' => PaymentStatusEnum::CANCELLED,
            'FAILED' => PaymentStatusEnum::FAILED,
            default => PaymentStatusEnum::UNKNOWN,
        };
    }
}
