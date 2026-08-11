<?php

declare(strict_types=1);

namespace Payments;

use Payments\DTO\PaymentRequest;
use Payments\DTO\PaymentResponse;
use Payments\Enum\PayCardMethodEnum;
use Payments\Gateways\PayCardGateway;

/**
 * Point d'entrée fluide pour PayCard (Guinée).
 *
 * Exemple :
 *   $response = PayCard::create($merchantCode, $apiKey)
 *       ->amount(50000)
 *       ->order('CMD-001')
 *       ->method(PayCardMethodEnum::ORANGE_MONEY)
 *       ->notify('https://monsite.com/webhook')
 *       ->phone('+224620000000')
 *       ->pay();
 *
 *   header('Location: ' . $response->redirectUrl);
 *
 * ⚠️ Voir le docblock de Payments\Gateways\PayCardGateway : l'URL de base et le
 * format exact des champs sont à confirmer auprès de PayCard avant la production.
 */
final class PayCard
{
    private PayCardGateway $gateway;

    private int $amount = 0;
    private string $currency = 'GNF';
    private ?string $orderId = null;
    private ?string $description = null;
    private ?string $notifyUrl = null;
    private ?string $phone = null;
    private ?string $email = null;
    private PayCardMethodEnum $method = PayCardMethodEnum::PAYCARD;

    private function __construct(string $merchantCode, string $apiKey, string $baseUrl)
    {
        $this->gateway = new PayCardGateway($merchantCode, $apiKey, $baseUrl);
    }

    public static function create(string $merchantCode, string $apiKey, string $baseUrl = 'https://api.paycard.co'): self
    {
        return new self($merchantCode, $apiKey, $baseUrl);
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

    public function notify(string $url): self
    {
        $this->notifyUrl = $url;

        return $this;
    }

    public function phone(string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function email(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Sous-méthode de paiement : ORANGE_MONEY, MOMO, CREDIT_CARD ou PAYCARD (défaut).
     */
    public function method(PayCardMethodEnum $method): self
    {
        $this->method = $method;

        return $this;
    }

    /**
     * Crée le paiement et renvoie l'URL de redirection.
     */
    public function pay(): PaymentResponse
    {
        return $this->gateway->initiate(new PaymentRequest(
            amount: $this->amount,
            currency: $this->currency,
            orderId: $this->orderId ?? uniqid('cmd_', true),
            description: $this->description,
            customerEmail: $this->email,
            customerPhone: $this->phone,
            notifyUrl: $this->notifyUrl,
            paymentMethod: $this->method->value,
        ));
    }

    public function status(string $operationReference): PaymentResponse
    {
        return $this->gateway->getStatus($operationReference);
    }
}
