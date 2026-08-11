<?php

declare(strict_types=1);

namespace Payments\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Exception levée pour toute erreur d'intégration ou d'appel API
 * (configuration manquante, erreur HTTP, réponse inattendue du fournisseur...).
 */
class PaymentException extends RuntimeException
{
    /** @var array<string,mixed> */
    private array $context;

    /**
     * @param array<string,mixed> $context Contexte additionnel (réponse brute, payload envoyé...)
     */
    public function __construct(string $message, array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }

    /**
     * @return array<string,mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
