<?php

declare(strict_types=1);

namespace Payments\Contracts;

use Payments\DTO\PaymentRequest;
use Payments\DTO\PaymentResponse;
use Payments\Exceptions\PaymentException;

/**
 * Contrat commun à toutes les passerelles de paiement du package.
 *
 * Chaque fournisseur (PayPal, Stripe, Visa Direct, Orange Money, PayCard)
 * a un flux d'intégration différent (redirection, formulaire carte côté client,
 * push serveur à serveur...). Ce contrat expose le plus petit dénominateur
 * commun utilisable de façon uniforme par l'application appelante.
 */
interface PaymentGatewayInterface
{
    /**
     * Nom technique unique de la passerelle (ex: "stripe", "orange_money").
     */
    public function getName(): string;

    /**
     * Initie un paiement auprès du fournisseur.
     *
     * Selon le fournisseur, la réponse contiendra soit une URL de redirection
     * (PayPal, Orange Money, PayCard), soit un client secret à utiliser côté
     * front (Stripe), soit une confirmation immédiate (Visa Direct).
     *
     * @throws PaymentException en cas d'erreur d'appel ou de configuration.
     */
    public function initiate(PaymentRequest $request): PaymentResponse;

    /**
     * Interroge le fournisseur pour connaître le statut réel d'une transaction.
     *
     * @param string $reference Référence renvoyée par initiate() (PaymentResponse::$reference).
     *
     * @throws PaymentException en cas d'erreur d'appel.
     */
    public function getStatus(string $reference): PaymentResponse;
}
