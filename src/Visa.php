<?php

declare(strict_types=1);

namespace Payments;

use Payments\DTO\PaymentRequest;
use Payments\DTO\PaymentResponse;
use Payments\Gateways\VisaGateway;

/**
 * Point d'entrée fluide pour Visa Direct (push payment vers une carte).
 *
 * Exemple :
 *   $response = Visa::create($apiKey, $sharedSecret, sandbox: true)
 *       ->amount(50.00, 'USD')
 *       ->order('CMD-001')
 *       ->card('4957030420210462')
 *       ->acquiringBin('408999')
 *       ->push();
 *
 *   echo $response->status->value;
 *
 * ⚠️ En production, Visa Direct impose en plus une authentification mutuelle TLS
 * (certificat client délivré par Visa) — voir le docblock de Payments\Gateways\VisaGateway.
 */
final class Visa
{
    private VisaGateway $gateway;

    private int $amountMinorUnits = 0;
    private string $currency = 'USD';
    private ?string $orderId = null;
    private array $meta = [];

    private function __construct(string $apiKey, string $sharedSecret, bool $sandbox)
    {
        $this->gateway = new VisaGateway($apiKey, $sharedSecret, $sandbox);
    }

    public static function create(string $apiKey, string $sharedSecret, bool $sandbox = true): self
    {
        return new self($apiKey, $sharedSecret, $sandbox);
    }

    /**
     * @param float $amount Montant en unité principale (ex: 50.00 pour 50 USD). Converti en centimes en interne.
     */
    public function amount(float $amount, string $currency = 'USD'): self
    {
        $this->amountMinorUnits = (int) round($amount * 100);
        $this->currency = $currency;

        return $this;
    }

    public function order(string $orderId): self
    {
        $this->orderId = $orderId;

        return $this;
    }

    public function card(string $cardNumber): self
    {
        $this->meta['recipientCardNumber'] = $cardNumber;

        return $this;
    }

    public function acquiringBin(string $bin): self
    {
        $this->meta['acquiringBIN'] = $bin;

        return $this;
    }

    public function senderName(string $name): self
    {
        $this->meta['senderName'] = $name;

        return $this;
    }

    /**
     * Champs Visa avancés non couverts par les méthodes ci-dessus
     * (cardAcceptorName, cardAcceptorCity, merchantCategoryCode...).
     */
    public function meta(array $meta): self
    {
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    /**
     * Envoie le push payment (OCT) vers la carte.
     */
    public function push(): PaymentResponse
    {
        return $this->gateway->initiate(new PaymentRequest(
            amount: $this->amountMinorUnits,
            currency: $this->currency,
            orderId: $this->orderId ?? uniqid('cmd_', true),
            metadata: $this->meta,
        ));
    }

    /**
     * @param string $acquiringBin
     * @param string $transactionIdentifier renvoyé dans PaymentResponse::$reference par push().
     */
    public function status(string $acquiringBin, string $transactionIdentifier): PaymentResponse
    {
        return $this->gateway->getStatus("{$acquiringBin}:{$transactionIdentifier}");
    }
}
