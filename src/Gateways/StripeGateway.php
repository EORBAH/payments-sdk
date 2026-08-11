<?php

declare(strict_types=1);

namespace Payments\Gateways;

use Payments\Contracts\PaymentGatewayInterface;
use Payments\Contracts\RefundableInterface;
use Payments\DTO\PaymentRequest;
use Payments\DTO\PaymentResponse;
use Payments\DTO\RefundRequest;
use Payments\Enum\PaymentStatusEnum;
use Payments\Exceptions\PaymentException;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;

/**
 * Passerelle Stripe basée sur l'API PaymentIntents (SDK officiel stripe/stripe-php).
 *
 * Documentation officielle :
 * - PaymentIntents : https://docs.stripe.com/api/payment_intents
 * - Accepter un paiement : https://docs.stripe.com/payments/accept-a-payment
 * - Remboursements : https://docs.stripe.com/api/refunds
 *
 * Flux : initiate() crée un PaymentIntent et renvoie son client_secret.
 * Le front-end doit ensuite confirmer le paiement avec Stripe.js /
 * Payment Element (stripe.confirmPayment côté navigateur). Ce package
 * ne gère volontairement pas la saisie carte : Stripe interdit de faire
 * transiter les données carte brutes par votre serveur sans certification PCI-DSS SAQ D.
 */
final class StripeGateway implements PaymentGatewayInterface, RefundableInterface
{
    private StripeClient $client;

    /**
     * @param string $secretKey Clé secrète Stripe (sk_test_... / sk_live_...).
     */
    public function __construct(string $secretKey)
    {
        $this->client = new StripeClient($secretKey);
    }

    public function getName(): string
    {
        return 'stripe';
    }

    public function initiate(PaymentRequest $request): PaymentResponse
    {
        try {
            /** @var PaymentIntent $intent */
            $intent = $this->client->paymentIntents->create([
                'amount' => $request->amount,
                'currency' => strtolower($request->currency),
                'description' => $request->description,
                'receipt_email' => $request->customerEmail,
                'metadata' => array_merge($request->metadata, [
                    'order_id' => $request->orderId,
                ]),
                'automatic_payment_methods' => ['enabled' => true],
            ]);
        } catch (ApiErrorException $e) {
            throw new PaymentException(
                'Échec de création du PaymentIntent Stripe : ' . $e->getMessage(),
                ['stripe_error' => $e->getError()?->toArray()],
                $e
            );
        }

        return new PaymentResponse(
            success: true,
            reference: $intent->id,
            status: $this->mapStatus($intent->status),
            redirectUrl: null,
            clientSecret: $intent->client_secret,
            message: null,
            raw: $intent->toArray(),
        );
    }

    public function getStatus(string $reference): PaymentResponse
    {
        try {
            $intent = $this->client->paymentIntents->retrieve($reference);
        } catch (ApiErrorException $e) {
            throw new PaymentException(
                'Échec de récupération du PaymentIntent Stripe : ' . $e->getMessage(),
                [],
                $e
            );
        }

        return new PaymentResponse(
            success: $intent->status === 'succeeded',
            reference: $intent->id,
            status: $this->mapStatus($intent->status),
            clientSecret: $intent->client_secret,
            raw: $intent->toArray(),
        );
    }

    public function refund(RefundRequest $request): PaymentResponse
    {
        try {
            /** @var Refund $refund */
            $refund = $this->client->refunds->create(array_filter([
                'payment_intent' => $request->reference,
                'amount' => $request->amount,
                'reason' => $request->reason,
            ]));
        } catch (ApiErrorException $e) {
            throw new PaymentException(
                'Échec du remboursement Stripe : ' . $e->getMessage(),
                [],
                $e
            );
        }

        return new PaymentResponse(
            success: $refund->status === 'succeeded' || $refund->status === 'pending',
            reference: $refund->id,
            status: $refund->status === 'succeeded' ? PaymentStatusEnum::SUCCESS : PaymentStatusEnum::PENDING,
            raw: $refund->toArray(),
        );
    }

    private function mapStatus(string $stripeStatus): PaymentStatusEnum
    {
        return match ($stripeStatus) {
            'succeeded' => PaymentStatusEnum::SUCCESS,
            'processing' => PaymentStatusEnum::PENDING,
            'requires_payment_method', 'requires_confirmation', 'requires_action', 'requires_capture' => PaymentStatusEnum::INITIATED,
            'canceled' => PaymentStatusEnum::CANCELLED,
            default => PaymentStatusEnum::UNKNOWN,
        };
    }
}
