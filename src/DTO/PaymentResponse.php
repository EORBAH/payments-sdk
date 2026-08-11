<?php

declare(strict_types=1);

namespace Payments\DTO;

use Payments\Enum\PaymentStatusEnum;

/**
 * Réponse normalisée retournée après l'initiation (ou la capture/le remboursement)
 * d'un paiement.
 */
final class PaymentResponse
{
    /**
     * @param bool                  $success       true si l'appel API a été accepté par le fournisseur
     *                                              (ne signifie pas forcément que l'argent est déjà débité :
     *                                              voir $status).
     * @param string                $reference     Identifiant de transaction côté fournisseur
     *                                              (payToken Orange Money, id Order PayPal,
     *                                              PaymentIntent Stripe, operationReference PayCard...).
     * @param PaymentStatusEnum     $status        Statut normalisé.
     * @param string|null           $redirectUrl   URL vers laquelle rediriger le client pour finaliser
     *                                              le paiement (PayPal, Orange Money, PayCard).
     *                                              Null pour les flux sans redirection (Stripe côté serveur,
     *                                              Visa Direct).
     * @param string|null           $clientSecret  Secret client à utiliser côté front (Stripe Elements/Payment Element).
     * @param string|null           $message       Message lisible (erreur ou confirmation).
     * @param array<string,mixed>   $raw           Réponse brute du fournisseur, pour debug/traçabilité.
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $reference,
        public readonly PaymentStatusEnum $status,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $clientSecret = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {
    }
}
