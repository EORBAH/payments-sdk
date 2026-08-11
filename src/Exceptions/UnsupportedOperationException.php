<?php

declare(strict_types=1);

namespace Payments\Exceptions;

/**
 * Levée quand une opération (ex: remboursement) est demandée à une passerelle
 * qui ne l'implémente/l'expose pas (ex: Orange Money, PayCard).
 */
class UnsupportedOperationException extends PaymentException
{
}
