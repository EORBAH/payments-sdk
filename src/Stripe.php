<?php

declare(strict_types=1);

namespace Payments;

use Payments\DTO\PaymentRequest;
use Payments\DTO\PaymentResponse;
use Payments\DTO\RefundRequest;
use Payments\Gateways\StripeGateway;

/**
 * Point d'entrée fluide pour Stripe — pas besoin de construire un PaymentRequest
 * à la main ni de passer par PaymentManager.
 *
 * Exemple :
 *   $response = Stripe::create('sk_test_...')
 *       ->amount(5000, 'usd')          // 50,00 USD
 *       ->order('CMD-001')
 *       ->email('client@example.com')
 *       ->pay();
 *
 *   echo $response->clientSecret; // à transmettre au front-end (Stripe.js)
 */
final class Stripe
{
    private StripeGateway $gateway;

    private int $amount = 0;
    private string $currency = 'usd';
    private ?string $orderId = null;
    private ?string $description = null;
    private ?string $email = null;
    private array $metadata = [];

    private function __construct(string $secretKey)
    {
        $this->gateway = new StripeGateway($secretKey);
    }

    public static function create(string $secretKey): self
    {
        return new self($secretKey);
    }

    /**
     * @param int $amount Montant en unité mineure (centimes). Ex: 5000 = 50,00.
     */
    public function amount(int $amount, string $currency = 'usd'): self
    {
        $this->amount = $amount;
        $this->currency = $currency;

        return $this;
    }

    public function order(string $orderId): self
    {
        $this->orderId = $orderId;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function email(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function metadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Crée le PaymentIntent et renvoie le clientSecret à transmettre au front-end.
     */
    public function pay(): PaymentResponse
    {
        return $this->gateway->initiate(new PaymentRequest(
            amount: $this->amount,
            currency: $this->currency,
            orderId: $this->orderId ?? uniqid('cmd_', true),
            description: $this->description,
            customerEmail: $this->email,
            metadata: $this->metadata,
        ));
    }

    /**
     * Récupère le statut réel d'un PaymentIntent (ex: après un webhook).
     */
    public function status(string $paymentIntentId): PaymentResponse
    {
        return $this->gateway->getStatus($paymentIntentId);
    }

    /**
     * Rembourse un paiement, total ou partiel.
     */
    public function refund(string $paymentIntentId, ?int $amount = null, ?string $reason = null): PaymentResponse
    {
        return $this->gateway->refund(new RefundRequest($paymentIntentId, $amount, $reason));
    }
}
