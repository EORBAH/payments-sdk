<?php

declare(strict_types=1);

namespace Payments\Enum;

/**
 * Méthodes de paiement disponibles via l'agrégateur PayCard (Guinée),
 * qui route lui-même vers Orange Money, MTN MoMo, carte bancaire (Visa/Mastercard)
 * ou le porte-monnaie PayCard natif.
 */
enum PayCardMethodEnum: string
{
    case ORANGE_MONEY = 'ORANGE_MONEY';
    case MOMO = 'MOMO';
    case CREDIT_CARD = 'CREDIT_CARD';
    case PAYCARD = 'PAYCARD';
}
