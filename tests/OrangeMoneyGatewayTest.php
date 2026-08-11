<?php

declare(strict_types=1);

namespace Payments\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Payments\DTO\PaymentRequest;
use Payments\Enum\PaymentStatusEnum;
use Payments\Gateways\OrangeMoneyGateway;
use PHPUnit\Framework\TestCase;

final class OrangeMoneyGatewayTest extends TestCase
{
    public function test_initiate_returns_redirect_url(): void
    {
        $mock = new MockHandler([
            // 1. réponse du endpoint OAuth2 /oauth/v3/token
            new Response(200, [], json_encode(['access_token' => 'fake-token', 'expires_in' => 3600])),
            // 2. réponse du endpoint /v1/webpayment
            new Response(200, [], json_encode([
                'payment_url' => 'https://webpayment.orange-money.com/gn/pay/xyz',
                'pay_token' => 'PAY-TOKEN-123',
                'notif_token' => 'NOTIF-TOKEN-123',
            ])),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $gateway = new OrangeMoneyGateway(
            clientId: 'id',
            clientSecret: 'secret',
            merchantKey: 'merchant-key',
            env: 'gn',
            httpClient: $client,
        );

        $response = $gateway->initiate(new PaymentRequest(
            amount: 50000,
            currency: 'GNF',
            orderId: 'CMD-001',
            description: 'Commande test',
            returnUrl: 'https://monsite.com/return',
            cancelUrl: 'https://monsite.com/cancel',
            notifyUrl: 'https://monsite.com/notify',
        ));

        self::assertTrue($response->success);
        self::assertSame('PAY-TOKEN-123', $response->reference);
        self::assertSame(PaymentStatusEnum::INITIATED, $response->status);
        self::assertSame('https://webpayment.orange-money.com/gn/pay/xyz', $response->redirectUrl);
    }
}
