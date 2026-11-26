<?php

/**
 * Turning RevenueCat REST API v2 responses into the wallet's value objects.
 *
 *   GET /v2/projects/{project}/customers/{customer}/purchases       → one-time purchases
 *   GET /v2/projects/{project}/customers/{customer}/subscriptions   → subscriptions
 *
 * Call `creditPurchases()` / `creditSubscription()` with the full lists every time the app opens: the wallet's claims
 * make repeats free. The client only says "check me"; the server reads RevenueCat itself and never trusts an amount
 * sent by the client.
 */

use Showcase\Wallet\Purchase;
use Showcase\Wallet\Subscription;

/** @return list<Purchase> */
function purchasesFromRevenueCat(array $items): array
{
    return array_map(fn (array $p) => new Purchase(
        id: (string) $p['id'],
        productId: (string) ($p['product']['store_identifier'] ?? ''),
        quantity: (int) ($p['quantity'] ?? 1),
        refunded: strtolower((string) ($p['status'] ?? '')) === 'refunded',
    ), $items);
}

/** @return list<Subscription> */
function subscriptionsFromRevenueCat(array $items): array
{
    return array_map(function (array $s) {
        $name = ($s['product']['store_identifier'] ?? '') . ' ' . ($s['product']['display_name'] ?? '');
        return new Subscription(
            id: (string) $s['id'],
            plan: preg_match('/year|annual/i', $name) ? 'annual' : 'monthly',
            active: !empty($s['gives_access']),
        );
    }, $items);
}
