<?php

declare(strict_types=1);

namespace Payments\Gateways;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Payments\Contracts\PaymentGatewayInterface;
use Payments\DTO\PaymentRequest;
use Payments\DTO\PaymentResponse;
use Payments\Enum\PaymentStatusEnum;
use Payments\Enum\PayCardMethodEnum;
use Payments\Exceptions\PaymentException;

/**
 * Passerelle PayCard — agrégateur de paiement guinéen (https://paycard.co)
 * permettant d'encaisser via Orange Money, MTN MoMo, carte bancaire (Visa/Mastercard)
 * ou le porte-monnaie PayCard natif, à travers une seule intégration.
 *
 * ⚠️ IMPORTANT — À LIRE AVANT D'UTILISER CETTE CLASSE :
 * PayCard ne publie pas de référence REST publique en ligne au moment de la
 * rédaction de ce package. La structure ci-dessous (endpoints, noms de champs,
 * en-tête d'authentification) est reconstituée à partir du comportement documenté
 * de leur intégration (ex: bibliothèque communautaire "paycard4j" qui suit ce même
 * modèle : createPayment(amount, description, reference, paymentMethod, callbackUrl)
 * → { paymentUrl, operationReference } puis getPaymentStatus(reference) → statut
 * parmi new/pending/success/failed/canceled/error).
 *
 * → Avant mise en production, confirmez auprès de votre interlocuteur commercial
 * PayCard (ou dans l'espace marchand paycard.co) : l'URL de base réelle de l'API,
 * le nom exact des en-têtes d'authentification, et le format exact des réponses.
 * Ajustez ensuite $baseUrl et, si besoin, les clés du payload dans ce fichier.
 */
final class PayCardGateway implements PaymentGatewayInterface
{
    private Client $http;

    /**
     * @param string $merchantCode Code marchand PayCard.
     * @param string $apiKey       Clé API/secret fournie par PayCard.
     * @param string $baseUrl      URL de base de l'API PayCard (à confirmer avec PayCard — voir avertissement ci-dessus).
     */
    public function __construct(
        private readonly string $merchantCode,
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.paycard.co',
        ?Client $httpClient = null,
    ) {
        $this->http = $httpClient ?? new Client([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
            'timeout' => 30,
        ]);
    }

    public function getName(): string
    {
        return 'paycard';
    }

    public function initiate(PaymentRequest $request): PaymentResponse
    {
        $method = $request->paymentMethod !== null
            ? PayCardMethodEnum::from($request->paymentMethod)
            : PayCardMethodEnum::PAYCARD;

        $payload = array_filter([
            'merchantCode' => $this->merchantCode,
            'amount' => $request->amount,
            'description' => $request->description,
            'reference' => $request->orderId,
            'paymentMethod' => $method->value,
            'callbackUrl' => $request->notifyUrl,
            'customerPhone' => $request->customerPhone,
            'customerEmail' => $request->customerEmail,
        ], static fn ($v) => $v !== null);

        $result = $this->request('POST', 'payments', $payload);

        if (!isset($result['paymentUrl'], $result['operationReference'])) {
            throw new PaymentException('Réponse PayCard inattendue lors de la création du paiement.', ['response' => $result]);
        }

        return new PaymentResponse(
            success: true,
            reference: $result['operationReference'],
            status: PaymentStatusEnum::INITIATED,
            redirectUrl: $result['paymentUrl'],
            raw: $result,
        );
    }

    public function getStatus(string $reference): PaymentResponse
    {
        $result = $this->request('GET', "payments/{$reference}/status", null);

        $status = $this->mapStatus($result['status'] ?? null);

        return new PaymentResponse(
            success: $status === PaymentStatusEnum::SUCCESS,
            reference: $reference,
            status: $status,
            raw: $result,
        );
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
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ];
            if ($payload !== null) {
                $options['json'] = $payload;
            }

            $response = $this->http->request($method, $uri, $options);
        } catch (GuzzleException $e) {
            throw new PaymentException("Échec de l'appel PayCard {$method} {$uri} : " . $e->getMessage(), [], $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function mapStatus(?string $paycardStatus): PaymentStatusEnum
    {
        return match ($paycardStatus) {
            'success' => PaymentStatusEnum::SUCCESS,
            'pending' => PaymentStatusEnum::PENDING,
            'new' => PaymentStatusEnum::INITIATED,
            'canceled' => PaymentStatusEnum::CANCELLED,
            'failed', 'error' => PaymentStatusEnum::FAILED,
            default => PaymentStatusEnum::UNKNOWN,
        };
    }
}
