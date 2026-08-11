<?php

declare(strict_types=1);

namespace Payments\Support;

/**
 * Génère l'en-tête X-Pay-Token exigé par le Visa Developer Platform
 * quand on utilise l'authentification "API Key – Shared Secret".
 *
 * Référence officielle :
 * https://developer.visa.com/pages/working-with-visa-apis/x-pay-token
 *
 * Formule : X-Pay-Token: xv2:{timestamp}:{hash}
 * où hash = SHA-256( sharedSecret + timestamp + resourcePath(+queryString) + requestBody )
 */
final class VisaXPayToken
{
    public static function generate(
        string $sharedSecret,
        string $resourcePathWithQuery,
        string $requestBody,
        ?int $timestamp = null
    ): string {
        $timestamp ??= time();

        $hash = hash('sha256', $sharedSecret . $timestamp . $resourcePathWithQuery . $requestBody);

        return "xv2:{$timestamp}:{$hash}";
    }
}
