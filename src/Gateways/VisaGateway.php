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
use Payments\Support\VisaXPayToken;

/**
 * Passerelle Visa Direct — API "Funds Transfer" (Original Credit Transaction / OCT)
 * permettant de créditer directement une carte Visa (push payment), par opposition
 * à un encaissement e-commerce classique.
 *
 * Documentation officielle :
 * - Vue d'ensemble : https://developer.visa.com/capabilities/visa_direct/docs
 * - Référence API pushfundstransactions : https://developer.visa.com/capabilities/visa_direct/reference
 * - Authentification : https://developer.visa.com/capabilities/visa_direct/docs-authentication
 *   (Two-Way SSL/mTLS obligatoire en production ; X-Pay-Token utilisable en complément/sandbox)
 *
 * ⚠️ En production, Visa Direct impose une authentification mutuelle TLS
 * (certificat client X.509 délivré par Visa) en plus, ou à la place, du X-Pay-Token.
 * Ce gateway gère le X-Pay-Token ; pour le mTLS, fournissez un client Guzzle
 * préconfiguré avec les options 'cert' / 'ssl_key' via le 4e paramètre du constructeur.
 *
 * Champs spécifiques Visa à fournir via PaymentRequest::$metadata :
 * - recipientCardNumber (string, obligatoire)
 * - acquiringBIN (string, obligatoire, fourni par Visa/votre acquéreur)
 * - acquirerCountryCode (string, ex: "484")
 * - senderName (string)
 * - cardAcceptorName, cardAcceptorCity, cardAcceptorCountryCode (string)
 * - businessApplicationId (string, ex: "AA" pour Account-to-Account)
 */
final class VisaGateway implements PaymentGatewayInterface
{
    private Client $http;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $sharedSecret,
        private readonly bool $sandbox = true,
        ?Client $httpClient = null,
    ) {
        $this->http = $httpClient ?? new Client([
            'base_uri' => $this->sandbox ? 'https://sandbox.api.visa.com' : 'https://api.visa.com',
            'timeout' => 30,
        ]);
    }

    public function getName(): string
    {
        return 'visa';
    }

    public function initiate(PaymentRequest $request): PaymentResponse
    {
        $meta = $request->metadata;

        foreach (['recipientCardNumber', 'acquiringBIN'] as $required) {
            if (empty($meta[$required])) {
                throw new PaymentException("Le champ metadata['{$required}'] est obligatoire pour Visa Direct.");
            }
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $payload = [
            'acquiringBin' => $meta['acquiringBIN'],
            'acquirerCountryCode' => $meta['acquirerCountryCode'] ?? '484',
            'amount' => number_format($request->amount / 100, 2, '.', ''),
            'businessApplicationId' => $meta['businessApplicationId'] ?? 'AA',
            'localTransactionDateTime' => $now->format('Y-m-d\TH:i:s'),
            'merchantCategoryCode' => $meta['merchantCategoryCode'] ?? '6012',
            'pointOfServiceData' => [
                'motoECIIndicator' => '0',
                'panEntryMode' => '90',
                'posConditionCode' => '00',
            ],
            'recipientPrimaryAccountNumber' => $meta['recipientCardNumber'],
            'retrievalReferenceNumber' => substr($request->orderId, 0, 12),
            'senderCurrencyCode' => $request->currency,
            'systemsTraceAuditNumber' => random_int(100000, 999999),
            'transactionCurrencyCode' => $request->currency,
            'transactionIdentifier' => $meta['transactionIdentifier'] ?? (string) random_int(100000000000, 999999999999),
            'cardAcceptor' => [
                'name' => $meta['cardAcceptorName'] ?? 'Merchant',
                'terminalId' => $meta['terminalId'] ?? 'TID0001',
                'idCode' => $meta['cardAcceptorIdCode'] ?? 'ID0001',
                'address' => [
                    'city' => $meta['cardAcceptorCity'] ?? 'Conakry',
                    'country' => $meta['cardAcceptorCountryCode'] ?? 'GIN',
                    'county' => $meta['cardAcceptorCounty'] ?? '',
                    'state' => $meta['cardAcceptorState'] ?? '',
                    'zipCode' => $meta['cardAcceptorZip'] ?? '00000',
                ],
            ],
        ];

        $result = $this->request('POST', '/visadirect/fundstransfer/v1/pushfundstransactions', $payload);

        $status = $this->mapStatus($result['actionCode'] ?? null);

        return new PaymentResponse(
            success: $status === PaymentStatusEnum::SUCCESS,
            reference: (string) ($result['transactionIdentifier'] ?? $payload['transactionIdentifier']),
            status: $status,
            message: $result['responseMessage'] ?? null,
            raw: $result,
        );
    }

    /**
     * Interroge le statut d'une transaction Visa Direct déjà soumise.
     * https://developer.visa.com/capabilities/visa_direct/reference#transactionquery
     *
     * @param string $reference transactionIdentifier renvoyé par initiate().
     *                          Nécessite acquiringBIN, transmis ici via un format "acquiringBIN:transactionIdentifier".
     */
    public function getStatus(string $reference): PaymentResponse
    {
        [$acquiringBin, $transactionIdentifier] = array_pad(explode(':', $reference, 2), 2, null);

        if ($acquiringBin === null || $transactionIdentifier === null) {
            throw new PaymentException(
                'Pour Visa Direct, $reference doit être au format "acquiringBIN:transactionIdentifier".'
            );
        }

        $result = $this->request(
            'GET',
            "/visadirect/fundstransfer/v1/transactionquery/{$acquiringBin}/{$transactionIdentifier}",
            null
        );

        $status = $this->mapStatus($result['actionCode'] ?? null);

        return new PaymentResponse(
            success: $status === PaymentStatusEnum::SUCCESS,
            reference: $transactionIdentifier,
            status: $status,
            raw: $result,
        );
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    private function request(string $method, string $resourcePath, ?array $payload): array
    {
        $body = $payload !== null ? json_encode($payload, JSON_THROW_ON_ERROR) : '';
        $queryString = "?apiKey={$this->apiKey}";

        $xPayToken = VisaXPayToken::generate(
            $this->sharedSecret,
            $resourcePath . $queryString,
            $body
        );

        try {
            $options = [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Pay-Token' => $xPayToken,
                ],
                'query' => ['apiKey' => $this->apiKey],
            ];
            if ($payload !== null) {
                $options['body'] = $body;
            }

            $response = $this->http->request($method, $resourcePath, $options);
        } catch (GuzzleException $e) {
            throw new PaymentException("Échec de l'appel Visa Direct {$method} {$resourcePath} : " . $e->getMessage(), [], $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function mapStatus(?string $actionCode): PaymentStatusEnum
    {
        // "00" = approuvé, cf. codes de réponse Visa Direct.
        return match ($actionCode) {
            '00' => PaymentStatusEnum::SUCCESS,
            null => PaymentStatusEnum::UNKNOWN,
            default => PaymentStatusEnum::FAILED,
        };
    }
}
