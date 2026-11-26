<?php

declare(strict_types=1);

namespace Showcase\Wallet;

/**
 * A one-time purchase as the billing provider reports it (RevenueCat, App Store Server API, Play Billing…).
 * `id` must be the provider's stable transaction id: it is what makes crediting idempotent.
 */
final class Purchase
{
    public function __construct(
        public readonly string $id,
        public readonly string $productId,
        public readonly int $quantity = 1,
        public readonly bool $refunded = false,
    ) {
    }
}
