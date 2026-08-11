<?php

declare(strict_types=1);

namespace Payments;

use Payments\Contracts\PaymentGatewayInterface;
use Payments\Exceptions\PaymentException;

/**
 * Point d'entrée unique du package : enregistre les passerelles configurées
 * et permet de les récupérer par leur nom (ex: "stripe", "orange_money").
 *
 * Exemple :
 *   $manager = new PaymentManager();
 *   $manager->register(new StripeGateway($secretKey));
 *   $manager->register(new OrangeMoneyGateway($id, $secret, $merchantKey));
 *
 *   $response = $manager->gateway('stripe')->initiate($paymentRequest);
 */
final class PaymentManager
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    public function register(PaymentGatewayInterface $gateway): self
    {
        $this->gateways[$gateway->getName()] = $gateway;

        return $this;
    }

    /**
     * @throws PaymentException si la passerelle demandée n'a pas été enregistrée.
     */
    public function gateway(string $name): PaymentGatewayInterface
    {
        return $this->gateways[$name]
            ?? throw new PaymentException("Aucune passerelle de paiement enregistrée sous le nom \"{$name}\".");
    }

    public function has(string $name): bool
    {
        return isset($this->gateways[$name]);
    }

    /**
     * @return string[] Noms des passerelles actuellement enregistrées.
     */
    public function names(): array
    {
        return array_keys($this->gateways);
    }
}
