<?php

declare(strict_types=1);

namespace Payments\DTO;

/**
 * Requête de paiement générique, commune à tous les fournisseurs.
 *
 * Selon le fournisseur, certains champs sont ignorés (ex: Visa Direct
 * n'utilise pas returnUrl/cancelUrl) et d'autres sont obligatoires
 * (ex: Orange Money exige notifyUrl). Voir la classe Gateway concernée.
 */
final class PaymentRequest
{
    /**
     * @param int         $amount        Montant en unité mineure de la devise pour Stripe/PayPal/Visa
     *                                    (ex: centimes) et en unité entière pour Orange Money/PayCard
     *                                    (ex: francs guinéens, qui n'ont pas de sous-unité).
     * @param string      $currency      Code devise ISO 4217 (ex: "USD", "EUR", "GNF", "XOF").
     * @param string      $orderId       Identifiant de commande côté marchand, unique.
     * @param string|null $description   Description affichée au client.
     * @param string|null $customerEmail
     * @param string|null $customerPhone Numéro au format international (ex: +224620000000)
     *                                   requis par Orange Money et PayCard.
     * @param string|null $returnUrl     URL de retour après paiement réussi (PayPal, Orange Money).
     * @param string|null $cancelUrl     URL de retour après annulation (PayPal, Orange Money).
     * @param string|null $notifyUrl     URL de callback/webhook serveur à serveur
     *                                   (Orange Money "notif_url", PayCard "callbackUrl").
     * @param string|null $paymentMethod Sous-méthode de paiement, utilisé par PayCard
     *                                   (voir Payments\Enum\PayCardMethodEnum).
     * @param array<string,mixed> $metadata Données libres transmises au fournisseur quand c'est possible.
     */
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $orderId,
        public readonly ?string $description = null,
        public readonly ?string $customerEmail = null,
        public readonly ?string $customerPhone = null,
        public readonly ?string $returnUrl = null,
        public readonly ?string $cancelUrl = null,
        public readonly ?string $notifyUrl = null,
        public readonly ?string $paymentMethod = null,
        public readonly array $metadata = [],
    ) {
    }
}
