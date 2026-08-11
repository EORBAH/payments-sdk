<?php

declare(strict_types=1);

namespace Payments;

use Payments\DTO\PaymentRequest;
use Payments\DTO\PaymentResponse;
use Payments\DTO\RefundRequest;
use Payments\Gateways\PayPalGateway;

/**
 * Point d'entrée fluide pour PayPal.
 *
 * Exemple :
 *   $response = PayPal::create($clientId, $secret, sandbox: true)
 *       ->amount(50.00, 'USD')
 *       ->order('CMD-001')
 *       ->returnTo('https://monsite.com/retour')
 *       ->cancelTo('https://monsite.com/annule')
 *       ->pay();
 *
 *   header('Location: ' . $response->redirectUrl);
 *
 *   // Au retour du client sur returnTo() :
 *   $result = PayPal::create($clientId, $secret, true)->capture($orderId);
 */
final class PayPal
{
    private PayPalGateway $gateway;

    private int $amountMinorUnits = 0;
    private string $currency = 'USD';
    private ?string $orderId = null;
    private ?string $description = null;
    private ?string $returnUrl = null;
    private ?string $cancelUrl = null;

    private function __construct(string $clientId, string $clientSecret, bool $sandbox)
    {
        $this->gateway = new PayPalGateway($clientId, $clientSecret, $sandbox);
    }

    public static function create(string $clientId, string $clientSecret, bool $sandbox = true): self
    {
        return new self($clientId, $clientSecret, $sandbox);
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

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function returnTo(string $url): self
    {
        $this->returnUrl = $url;

        return $this;
    }

    public function cancelTo(string $url): self
    {
        $this->cancelUrl = $url;

        return $this;
    }

    /**
     * Crée la commande PayPal et renvoie l'URL "approve" vers laquelle rediriger le client.
     */
    public function pay(): PaymentResponse
    {
        return $this->gateway->initiate(new PaymentRequest(
            amount: $this->amountMinorUnits,
            currency: $this->currency,
            orderId: $this->orderId ?? uniqid('cmd_', true),
            description: $this->description,
            returnUrl: $this->returnUrl,
            cancelUrl: $this->cancelUrl,
        ));
    }

    /**
     * Finalise le débit après que le client a approuvé la commande (retour sur returnTo()).
     */
    public function capture(string $paypalOrderId): PaymentResponse
    {
        return $this->gateway->capture($paypalOrderId);
    }

    public function status(string $paypalOrderId): PaymentResponse
    {
        return $this->gateway->getStatus($paypalOrderId);
    }

    /**
     * @param string $captureId Identifiant de la "capture" (pas de l'order), renvoyé par capture().
     * @param float|null $amount Montant à rembourser en unité principale. Null = remboursement total.
     */
    public function refund(string $captureId, ?float $amount = null, ?string $reason = null): PaymentResponse
    {
        return $this->gateway->refund(new RefundRequest(
            reference: $captureId,
            amount: $amount !== null ? (int) round($amount * 100) : null,
            reason: $reason,
        ));
    }
}
