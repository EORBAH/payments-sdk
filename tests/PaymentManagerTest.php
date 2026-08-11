<?php

declare(strict_types=1);

namespace Payments\Tests;

use Payments\Contracts\PaymentGatewayInterface;
use Payments\DTO\PaymentRequest;
use Payments\DTO\PaymentResponse;
use Payments\Enum\PaymentStatusEnum;
use Payments\Exceptions\PaymentException;
use Payments\PaymentManager;
use PHPUnit\Framework\TestCase;

final class PaymentManagerTest extends TestCase
{
    public function test_it_registers_and_resolves_a_gateway(): void
    {
        $manager = new PaymentManager();
        $manager->register($this->fakeGateway('stripe'));

        self::assertTrue($manager->has('stripe'));
        self::assertSame(['stripe'], $manager->names());
        self::assertSame('stripe', $manager->gateway('stripe')->getName());
    }

    public function test_it_throws_when_gateway_is_unknown(): void
    {
        $manager = new PaymentManager();

        $this->expectException(PaymentException::class);

        $manager->gateway('inconnu');
    }

    private function fakeGateway(string $name): PaymentGatewayInterface
    {
        return new class ($name) implements PaymentGatewayInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function initiate(PaymentRequest $request): PaymentResponse
            {
                return new PaymentResponse(true, 'ref', PaymentStatusEnum::INITIATED);
            }

            public function getStatus(string $reference): PaymentResponse
            {
                return new PaymentResponse(true, $reference, PaymentStatusEnum::SUCCESS);
            }
        };
    }
}
