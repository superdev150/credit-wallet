<?php

declare(strict_types=1);

namespace Showcase\Wallet;

use Showcase\Wallet\Store\Store;
use Showcase\Wallet\Store\Transaction;

/**
 * A virtual-currency wallet kept on the server, where the client cannot edit it.
 *
 * Data (paths in the Store):
 *   wallets/{uid}               { free, paid, daily {last, streak}, sub {welcomed, last}, merged_into?, created_at, updated_at }
 *   wallets/{uid}/ledger/{id}   { reason, free, paid, ref, after {free, paid}, at }      one entry per change, never edited
 *   claims/{sha256(key)}        { key, uid, at }                                          things granted ONCE, ever
 *
 * Two balances: `free` (bonuses: capped low, spent first) and `paid` (purchases and earned rewards). Spending free
 * credits first means a refund of a purchase never has to claw back bonus credits.
 *
 * Idempotency comes from claims, not from "has this user received X": a store transaction id, a subscription period, a
 * device's welcome bonus. Each one is claimed by creating a document inside the same transaction that credits the
 * wallet. Because the claim is a read and a create in that transaction, two concurrent requests cannot both grant it:
 * the second one sees the claim on retry. It does not matter whether the same user or a different one asks.
 */
final class Wallet
{
    /** @param \Closure(): \DateTimeImmutable|null $clock */
    public function __construct(
        private Store $store,
        private Economy $economy = new Economy(),
        private ?\Closure $clock = null,
    ) {
    }

    // ───────────────────────────── reading

    public function balance(string $uid): Balance
    {
        return Balance::of($this->store->get($this->walletPath($uid)));
    }

    /** @return list<array> newest last */
    public function ledger(string $uid): array
    {
        $rows = array_column($this->store->list($this->walletPath($uid) . '/ledger'), 'data', 'path');
        ksort($rows);
        return array_values($rows);
    }

    // ───────────────────────────── opening

    /**
     * First contact from a client. Creates the wallet if needed:
     *  - with `$legacy` (credits the app kept on the device before the server owned them), imports them once per
     *    device, within the caps;
     *  - otherwise grants the welcome bonus once per device. Signing out and in with a new account on the same phone
     *    does not grant it again.
     */
    public function open(string $uid, string $deviceId, ?array $legacy = null): Balance
    {
        $device = $deviceId !== '' ? "dev:{$deviceId}" : "uid:{$uid}";

        $this->mutate($uid, function (?array $w, \Closure $has) use ($device, $legacy) {
            if ($w !== null) {
                return null;
            }
            $w = self::blank();
            if ($legacy !== null && !$has("welcome:{$device}") && !$has("import:{$device}")) {
                $w['free'] = self::clamp((int) ($legacy['free'] ?? 0), $this->economy->maxFree);
                $w['paid'] = self::clamp((int) ($legacy['paid'] ?? 0), $this->economy->maxPaid);
                return [
                    'wallet' => $w,
                    'claims' => ["welcome:{$device}", "import:{$device}"],
                    'ledger' => [['import', $w['free'], $w['paid'], $device]],
                ];
            }
            if (!$has("welcome:{$device}")) {
                $w['free'] = min($this->economy->maxFree, $this->economy->welcome);
                return ['wallet' => $w, 'claims' => ["welcome:{$device}"], 'ledger' => [['welcome', $w['free'], 0, $device]]];
            }
            return ['wallet' => $w, 'claims' => [], 'ledger' => []];
        });

        return $this->balance($uid);
    }

    // ───────────────────────────── billing

    /**
     * Credits every purchase not yet credited, to ANY user. Call it with the full purchase history whenever the app
     * opens or a purchase completes: it is safe to repeat.
     *
     * @param list<Purchase> $purchases
     * @return int credits added now
     */
    public function creditPurchases(string $uid, array $purchases): int
    {
        $fresh = [];
        foreach ($purchases as $p) {
            $credits = ($this->economy->packs[$p->productId] ?? 0) * max(1, $p->quantity);
            if ($p->id !== '' && $credits > 0 && !$p->refunded) {
                $fresh[$p->id] = $credits;   // duplicates in the input collapse here
            }
        }
        if (!$fresh) {
            return 0;
        }

        $added = 0;
        $this->mutate($uid, function (?array $w, \Closure $has) use ($fresh, &$added) {
            $added = 0;   // $fn may run more than once
            $w ??= self::blank();
            $claims = $ledger = [];
            foreach ($fresh as $id => $credits) {
                if ($has("purchase:{$id}")) {
                    continue;
                }
                $before = $w['paid'];
                $w['paid'] = min($this->economy->maxPaid, $before + $credits);
                $added += $w['paid'] - $before;
                $claims[] = "purchase:{$id}";
                $ledger[] = ['purchase', 0, $w['paid'] - $before, $id];
            }
            return $claims ? ['wallet' => $w, 'claims' => $claims, 'ledger' => $ledger] : null;
        });
        return $added;
    }

    /**
     * Subscription credits, once per period per subscription: the plan bonus the first time this wallet ever has a
     * plan, the renewal amount afterwards. The claim is per subscription, so one subscription shared by two accounts
     * (family sharing, restored purchases) pays only once per period.
     *
     * @param list<Subscription> $subscriptions
     */
    public function creditSubscription(string $uid, array $subscriptions): int
    {
        $sub = null;
        foreach ($subscriptions as $s) {
            if ($s->active) {
                $sub = $s;
                break;
            }
        }
        if ($sub === null) {
            return 0;
        }
        $now = $this->now();
        $period = $sub->periodOf($now);

        $added = 0;
        $this->mutate($uid, function (?array $w, \Closure $has) use ($sub, $period, $now, &$added) {
            $added = 0;
            if ($has("sub:{$sub->id}:{$period}")) {
                return null;
            }
            $w ??= self::blank();
            $first = empty($w['sub']['welcomed']);
            $credits = $first ? $this->economy->planBonus[$sub->plan] : $this->economy->planRenewal[$sub->plan];
            $before = $w['free'];
            $w['free'] = min($this->economy->maxFree, $before + $credits);
            $w['sub'] = ['welcomed' => true, 'last' => $now->format(DATE_ATOM)];
            $added = $w['free'] - $before;
            return [
                'wallet' => $w,
                'claims' => ["sub:{$sub->id}:{$period}"],
                'ledger' => [[$first ? 'plan_bonus' : 'plan_renewal', $added, 0, "{$sub->id}:{$period}"]],
            ];
        });
        return $added;
    }

    // ───────────────────────────── earning

    /**
     * Daily reward. `$localDay` is the user's calendar date (YYYY-MM-DD) as the phone sees it: accepted within ±1 day
     * of UTC (every timezone fits) and only if later than the last claimed day, so changing the phone's clock forward
     * and back cannot farm it. The streak continues when the last claim was the day before.
     *
     * @return int credits granted (0 = already claimed today)
     */
    public function claimDaily(string $uid, string $localDay): int
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $localDay, new \DateTimeZone('UTC'));
        if (!$day || $day->format('Y-m-d') !== $localDay) {
            throw new \InvalidArgumentException('localDay must be YYYY-MM-DD');
        }
        $today = $this->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        if (abs(($day->getTimestamp() - $today->getTimestamp()) / 86400) > 1) {
            throw new \InvalidArgumentException('localDay is too far from today');
        }
        $yesterday = $day->modify('-1 day')->format('Y-m-d');
        $rewards = $this->economy->dailyRewards;

        $granted = 0;
        $this->mutate($uid, function (?array $w) use ($localDay, $yesterday, $rewards, &$granted) {
            $granted = 0;
            $w ??= self::blank();
            $last = (string) $w['daily']['last'];
            if ($last !== '' && strcmp($localDay, $last) <= 0) {
                return null;
            }
            $index = $last === $yesterday ? ((int) $w['daily']['streak']) % count($rewards) : 0;
            $before = $w['paid'];
            $w['paid'] = min($this->economy->maxPaid, $before + $rewards[$index]);
            $w['daily'] = ['last' => $localDay, 'streak' => $index + 1];
            $granted = $w['paid'] - $before;
            return ['wallet' => $w, 'claims' => [], 'ledger' => [['daily', 0, $granted, $localDay]]];
        });
        return $granted;
    }

    /** One rewarded ad. Rate-limit it in the HTTP layer; verify it with the ad network's server-side callback if you can. */
    public function creditAd(string $uid, string $adRef = ''): int
    {
        return $this->creditOnce($uid, $adRef !== '' ? "ad:{$adRef}" : null, $this->economy->perAd, 'ad', $adRef);
    }

    /** Reward for content of this user that a moderator approved: once per piece of content. */
    public function creditApproval(string $uid, string $contentId): int
    {
        return $this->creditOnce($uid, "approval:{$contentId}", $this->economy->approvalReward, 'approval', $contentId);
    }

    // ───────────────────────────── spending

    /**
     * Charge `$cost`, free credits first. Charge BEFORE doing the expensive work and refund() if it fails: charging
     * afterwards lets a user with 0 credits start ten paid jobs in parallel.
     *
     * @return array{free: int, paid: int} receipt for refund()
     * @throws NotEnoughCredits
     */
    public function spend(string $uid, int $cost, string $reason, string $ref = ''): array
    {
        if ($cost <= 0) {
            throw new \InvalidArgumentException('cost must be positive');
        }
        $receipt = ['free' => 0, 'paid' => 0];
        $this->mutate($uid, function (?array $w) use ($cost, $reason, $ref, &$receipt) {
            $w ??= self::blank();
            if ($w['free'] + $w['paid'] < $cost) {
                throw new NotEnoughCredits(Balance::of($w), $cost);
            }
            $fromFree = min($w['free'], $cost);
            $receipt = ['free' => $fromFree, 'paid' => $cost - $fromFree];
            $w['free'] -= $receipt['free'];
            $w['paid'] -= $receipt['paid'];
            return ['wallet' => $w, 'claims' => [], 'ledger' => [[$reason, -$receipt['free'], -$receipt['paid'], $ref]]];
        });
        return $receipt;
    }

    /**
     * Give back what spend() took, to the same buckets. Pass the same `$ref` you used to spend and it is refunded at
     * most once, even if the failure handler runs twice.
     *
     * @param array{free: int, paid: int} $receipt
     */
    public function refund(string $uid, array $receipt, string $ref): void
    {
        $this->mutate($uid, function (?array $w, \Closure $has) use ($receipt, $ref) {
            if ($has("refund:{$ref}")) {
                return null;
            }
            $w ??= self::blank();
            $w['free'] += (int) $receipt['free'];   // no cap: it was ours a second ago
            $w['paid'] += (int) $receipt['paid'];
            return [
                'wallet' => $w,
                'claims' => ["refund:{$ref}"],
                'ledger' => [['refund', (int) $receipt['free'], (int) $receipt['paid'], $ref]],
            ];
        });
    }

    // ───────────────────────────── accounts

    /**
     * An anonymous user signs in with a real account: move the anonymous wallet into the account (within caps) and
     * leave a tombstone (`merged_into`) so it can never be merged twice. Streak and plan state keep the most advanced
     * of the two. Only call this after proving the caller owns `$fromUid` (e.g. with its still-valid ID token).
     */
    public function merge(string $intoUid, string $fromUid): void
    {
        if ($fromUid === '' || $fromUid === $intoUid) {
            return;
        }
        $to = $this->walletPath($intoUid);
        $from = $this->walletPath($fromUid);

        $this->store->transaction(function (Transaction $t) use ($to, $from, $intoUid, $fromUid) {
            $b = $t->get($from);
            $a = $t->get($to) ?? self::blank();
            if ($b === null || !empty($b['merged_into'])) {
                return;
            }
            $now = $this->now()->format(DATE_ATOM);

            $merged = $a;
            $merged['free'] = min($this->economy->maxFree, $a['free'] + (int) $b['free']);
            $merged['paid'] = min($this->economy->maxPaid, $a['paid'] + (int) $b['paid']);
            if (strcmp((string) $b['daily']['last'], (string) $a['daily']['last']) > 0) {
                $merged['daily'] = $b['daily'];
            }
            $merged['sub'] = [
                'welcomed' => !empty($a['sub']['welcomed']) || !empty($b['sub']['welcomed']),
                'last' => max((string) $a['sub']['last'], (string) $b['sub']['last']),
            ];
            $merged['created_at'] ??= $now;
            $merged['updated_at'] = $now;

            $t->set($to, $merged);
            $t->set($from, ['free' => 0, 'paid' => 0, 'merged_into' => $intoUid, 'updated_at' => $now] + $b);
            $t->create($this->ledgerPath($to), $this->entry('merge_in', $merged['free'] - $a['free'], $merged['paid'] - $a['paid'], $fromUid, $merged, $now));
            $t->create($this->ledgerPath($from), $this->entry('merge_out', -(int) $b['free'], -(int) $b['paid'], $intoUid, ['free' => 0, 'paid' => 0], $now));
        });
    }

    /** Account deletion: wallet and ledger go; claims stay, so deleting and re-creating cannot re-earn one-time bonuses. */
    public function delete(string $uid): void
    {
        $path = $this->walletPath($uid);
        foreach ($this->store->list("{$path}/ledger") as $row) {
            $this->store->delete($row['path']);
        }
        $this->store->delete($path);
    }

    // ───────────────────────────── internals

    /** @internal */
    public static function blank(): array
    {
        return ['free' => 0, 'paid' => 0, 'daily' => ['last' => '', 'streak' => 0], 'sub' => ['welcomed' => false, 'last' => '']];
    }

    private function creditOnce(string $uid, ?string $claim, int $amount, string $reason, string $ref): int
    {
        $granted = 0;
        $this->mutate($uid, function (?array $w, \Closure $has) use ($claim, $amount, $reason, $ref, &$granted) {
            $granted = 0;
            if ($claim !== null && $has($claim)) {
                return null;
            }
            $w ??= self::blank();
            $before = $w['paid'];
            $w['paid'] = min($this->economy->maxPaid, $before + $amount);
            $granted = $w['paid'] - $before;
            return ['wallet' => $w, 'claims' => $claim !== null ? [$claim] : [], 'ledger' => [[$reason, 0, $granted, $ref]]];
        });
        return $granted;
    }

    /**
     * The one way the wallet changes. `$fn(?array $wallet, Closure $has)` returns null (nothing to do) or
     * ['wallet' => …, 'claims' => [keys], 'ledger' => [[reason, free, paid, ref], …]]. `$has($key)` reads a claim
     * inside the transaction. `$fn` can run several times (the store retries on conflicts), so it must not touch
     * anything outside its arguments and return value.
     */
    private function mutate(string $uid, callable $fn): void
    {
        $path = $this->walletPath($uid);

        $this->store->transaction(function (Transaction $t) use ($path, $uid, $fn) {
            $wallet = $t->get($path);
            $change = $fn($wallet, fn (string $key) => $t->exists($this->claimPath($key)));
            if (!$change) {
                return;
            }

            $now = $this->now()->format(DATE_ATOM);
            $w = $change['wallet'];
            $w['free'] = (int) $w['free'];
            $w['paid'] = (int) $w['paid'];
            $w['created_at'] ??= $now;
            $w['updated_at'] = $now;

            // Reads are done: from here on, only writes (Firestore requires that order)
            $t->set($path, $w);
            foreach ($change['claims'] as $key) {
                $t->create($this->claimPath($key), ['key' => $key, 'uid' => $uid, 'at' => $now]);
            }
            foreach ($change['ledger'] as [$reason, $free, $paid, $ref]) {
                $t->create($this->ledgerPath($path), $this->entry($reason, $free, $paid, $ref, $w, $now));
            }
        });
    }

    private function entry(string $reason, int $free, int $paid, string $ref, array $after, string $now): array
    {
        return ['reason' => $reason, 'free' => $free, 'paid' => $paid, 'ref' => $ref, 'after' => ['free' => (int) $after['free'], 'paid' => (int) $after['paid']], 'at' => $now];
    }

    private function walletPath(string $uid): string
    {
        if ($uid === '' || str_contains($uid, '/')) {
            throw new \InvalidArgumentException('Invalid uid');
        }
        return "wallets/{$uid}";
    }

    private int $sequence = 0;

    /** Sortable, unique ledger ids: time, then a per-process counter, then randomness against other processes. */
    private function ledgerPath(string $walletPath): string
    {
        return sprintf('%s/ledger/%s-%06d-%s', $walletPath, $this->now()->format('YmdHisu'), ++$this->sequence, bin2hex(random_bytes(3)));
    }

    /** Hashed: provider ids can contain "/" (invalid in a document id) and claims should not leak device ids. */
    private function claimPath(string $key): string
    {
        return 'claims/' . hash('sha256', $key);
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock ? ($this->clock)() : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private static function clamp(int $value, int $max): int
    {
        return max(0, min($max, $value));
    }
}
