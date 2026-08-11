<?php

declare(strict_types=1);

namespace Payments\Contracts;

use Payments\DTO\PaymentResponse;
use Payments\DTO\RefundRequest;
use Payments\Exceptions\PaymentException;

/**
 * À implémenter par les passerelles qui supportent le remboursement
 * via API (Stripe, PayPal). Les passerelles mobile money de ce package
 * (Orange Money, PayCard) ne l'implémentent pas car le remboursement
 * s'y opère hors API (procédure marchand).
 */
interface RefundableInterface
{
    /**
     * @throws PaymentException
     */
    public function refund(RefundRequest $request): PaymentResponse;
}
