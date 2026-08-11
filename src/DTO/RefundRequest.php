<?php

declare(strict_types=1);

namespace Payments\DTO;

final class RefundRequest
{
    /**
     * @param string      $reference Référence de la transaction à rembourser.
     * @param int|null    $amount    Montant à rembourser (unité mineure). Null = remboursement total.
     * @param string|null $reason    Motif du remboursement.
     */
    public function __construct(
        public readonly string $reference,
        public readonly ?int $amount = null,
        public readonly ?string $reason = null,
    ) {
    }
}
