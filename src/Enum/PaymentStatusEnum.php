<?php

declare(strict_types=1);

namespace Payments\Enum;

/**
 * Statut normalisé d'une transaction, quel que soit le fournisseur.
 * Chaque passerelle (Gateway) mappe le statut natif du fournisseur
 * vers une de ces valeurs.
 */
enum PaymentStatusEnum: string
{
    case INITIATED = 'initiated';   // Transaction créée, en attente d'action du client
    case PENDING    = 'pending';     // En cours de traitement côté fournisseur
    case SUCCESS    = 'success';     // Paiement confirmé
    case FAILED     = 'failed';      // Paiement refusé / échoué
    case CANCELLED  = 'cancelled';   // Annulé par le client ou expiré
    case UNKNOWN    = 'unknown';     // Statut non reconnu / non mappé
}
