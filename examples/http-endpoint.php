<?php

/**
 * How the wallet sits behind a paid AI endpoint (simplified from production).
 *
 * The order matters:
 *   1. authenticate (Firebase ID token → uid; see superdev150/firebase-id-token-verifier)
 *   2. charge BEFORE calling the paid API: concurrent requests cannot all slip through on the same balance
 *   3. refund with the same ref if the provider fails, so the user never pays for an error
 */

use Showcase\Wallet\NotEnoughCredits;
use Showcase\Wallet\Wallet;

const COST_PER_IMAGE = 30;

function generateImage(Wallet $wallet, string $uid, string $prompt, callable $callImageApi): array
{
    $ref = 'gen-' . bin2hex(random_bytes(8));

    try {
        $receipt = $wallet->spend($uid, COST_PER_IMAGE, 'generate', $ref);
    } catch (NotEnoughCredits $e) {
        return [402, ['code' => 'not_enough_credits', 'wallet' => $e->balance]];
    }

    try {
        $url = $callImageApi($prompt);
    } catch (\Throwable $e) {
        $wallet->refund($uid, $receipt, $ref);
        return [502, ['code' => 'generation_failed', 'wallet' => $wallet->balance($uid)]];
    }

    return [200, ['url' => $url, 'wallet' => $wallet->balance($uid)]];
}
