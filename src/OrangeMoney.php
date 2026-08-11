<?php

declare(strict_types=1);

namespace Payments;

use Payments\DTO\PaymentRequest;
use Payments\DTO\PaymentResponse;
use Payments\Gateways\OrangeMoneyGateway;

/**
 * Point d'entrée fluide pour Orange Money (Guinée).
 *
 * Exemple :
 *   $response = OrangeMoney::create($clientId, $clientSecret, $merchantKey, 'gn')
 *       ->amount(50000) // GNF, pas de sous-unité
 *       ->order('CMD-001')
 *       ->returnTo('https://monsite.com/retour')
 *       ->cancelTo('https://monsite.com/annule')
 *       ->notify('https://monsite.com/webhook')
 *       ->pay();
 *
 *   header('Location: ' . $response->redirectUrl);
 *
 *   // Vérification du statut (webhook ou job de reconciliation) :
 *   $status = OrangeMoney::create(...)->status($payToken, 'CMD-001', 50000);
 */
final class OrangeMoney
{
    private OrangeMoneyGateway $gateway;

    private int $amount = 0;
    private string $currency = 'GNF';
    private ?string $orderId = null;
    private ?string $description = null;
    private ?string $returnUrl = null;
    private ?string $cancelUrl = null;
    private ?string $notifyUrl = null;
    private string $lang = 'fr';

    private function __construct(string $clientId, string $clientSecret, string $merchantKey, string $env)
    {
        $this->gateway = new OrangeMoneyGateway($clientId, $clientSecret, $merchantKey, $env);
    }

    /**
     * @param string $env "gn" pour la Guinée en production, "dev" pour le bac à sable.
     */
    public static function create(string $clientId, string $clientSecret, string $merchantKey, string $env = 'gn'): self
    {
        return new self($clientId, $clientSecret, $merchantKey, $env);
    }

    /**
     * @param int $amount Montant en francs guinéens (pas de sous-unité).
     */
    public function amount(int $amount, string $currency = 'GNF'): self
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

    public function notify(string $url): self
    {
        $this->notifyUrl = $url;

        return $this;
    }

    public function lang(string $lang): self
    {
        $this->lang = $lang;

        return $this;
    }

    /**
     * Crée le paiement et renvoie l'URL de redirection (le client y valide via OTP USSD).
     */
    public function pay(): PaymentResponse
    {
        return $this->gateway->initiate(new PaymentRequest(
            amount: $this->amount,
            currency: $this->currency,
            orderId: $this->orderId ?? uniqid('cmd_', true),
            description: $this->description,
            returnUrl: $this->returnUrl,
            cancelUrl: $this->cancelUrl,
            notifyUrl: $this->notifyUrl,
            metadata: ['lang' => $this->lang],
        ));
    }

    /**
     * @param string $payToken Référence renvoyée par pay() (PaymentResponse::$reference).
     */
    public function status(string $payToken, string $orderId, int $amount): PaymentResponse
    {
        return $this->gateway->getStatus($payToken, $orderId, $amount);
    }
}
