<?php

declare(strict_types=1);

namespace Showcase\Wallet\Tests;

use PHPUnit\Framework\TestCase;
use Showcase\Wallet\Economy;
use Showcase\Wallet\NotEnoughCredits;
use Showcase\Wallet\Purchase;
use Showcase\Wallet\Store\InMemoryStore;
use Showcase\Wallet\Subscription;
use Showcase\Wallet\Wallet;

final class WalletTest extends TestCase
{
    private InMemoryStore $store;
    private Wallet $wallet;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->now = new \DateTimeImmutable('2026-03-10 12:00:00', new \DateTimeZone('UTC'));
        $this->wallet = new Wallet($this->store, new Economy(), fn () => $this->now);
    }

    // ───────────── opening

    public function testWelcomeBonusOncePerDevice(): void
    {
        self::assertSame(30, $this->wallet->open('alice', 'phone-1')->total());

        // Same phone, new account (signed out and back in): no second welcome
        self::assertSame(0, $this->wallet->open('alice-2', 'phone-1')->total());
        // Another phone: welcome
        self::assertSame(30, $this->wallet->open('bob', 'phone-2')->total());
    }

    public function testOpeningTwiceDoesNothing(): void
    {
        $this->wallet->open('alice', 'phone-1');
        $this->wallet->spend('alice', 10, 'generate');
        self::assertSame(20, $this->wallet->open('alice', 'phone-1')->total());
    }

    public function testImportsLegacyDeviceBalanceOnceWithinCaps(): void
    {
        $b = $this->wallet->open('alice', 'phone-1', ['free' => 5000, 'paid' => 120]);
        self::assertSame([200, 120], [$b->free, $b->paid]);   // free is capped

        // A second account on the same phone cannot import (or get welcomed) again
        self::assertSame(0, $this->wallet->open('alice-2', 'phone-1', ['free' => 50, 'paid' => 50])->total());
    }

    // ───────────── purchases

    public function testEachPurchaseIsCreditedOnceEverAcrossUsers(): void
    {
        $this->wallet->open('alice', 'p1');
        $purchases = [new Purchase('tx-1', 'credits.small'), new Purchase('tx-1', 'credits.small'), new Purchase('tx-2', 'credits.large')];

        self::assertSame(600, $this->wallet->creditPurchases('alice', $purchases));
        self::assertSame(0, $this->wallet->creditPurchases('alice', $purchases));
        // The same receipt replayed against another account (restore purchases on a friend's phone)
        self::assertSame(0, $this->wallet->creditPurchases('mallory', $purchases));

        self::assertSame(630, $this->wallet->balance('alice')->total());
        self::assertSame(0, $this->wallet->balance('mallory')->total());
    }

    public function testRefundedAndUnknownProductsGiveNothing(): void
    {
        self::assertSame(0, $this->wallet->creditPurchases('alice', [
            new Purchase('tx-1', 'credits.small', refunded: true),
            new Purchase('tx-2', 'pro.monthly'),
        ]));
    }

    public function testQuantityMultiplies(): void
    {
        self::assertSame(300, $this->wallet->creditPurchases('alice', [new Purchase('tx-1', 'credits.small', quantity: 3)]));
    }

    // ───────────── subscriptions

    public function testPlanBonusThenOneRenewalPerMonth(): void
    {
        $sub = [new Subscription('sub-1', 'monthly', true)];

        self::assertSame(100, $this->wallet->creditSubscription('alice', $sub));   // first ever: bonus
        self::assertSame(0, $this->wallet->creditSubscription('alice', $sub));     // same month

        $this->now = $this->now->modify('+1 month');
        self::assertSame(80, $this->wallet->creditSubscription('alice', $sub));    // renewal
    }

    public function testASharedSubscriptionPaysOncePerPeriod(): void
    {
        $sub = [new Subscription('family-sub', 'annual', true)];
        self::assertSame(150, $this->wallet->creditSubscription('parent', $sub));
        self::assertSame(0, $this->wallet->creditSubscription('child', $sub));
    }

    public function testInactiveSubscriptionGivesNothing(): void
    {
        self::assertSame(0, $this->wallet->creditSubscription('alice', [new Subscription('s', 'monthly', false)]));
    }

    public function testFreeBucketIsCapped(): void
    {
        $this->wallet->open('alice', 'p1');   // 30 free
        $sub = [new Subscription('sub-1', 'monthly', true)];
        $this->wallet->creditSubscription('alice', $sub);   // +100 → 130
        $this->now = $this->now->modify('+1 month');
        self::assertSame(70, $this->wallet->creditSubscription('alice', $sub));   // 80 wanted, cap 200
        self::assertSame(200, $this->wallet->balance('alice')->free);
    }

    // ───────────── daily

    public function testDailyStreakAndOncePerDay(): void
    {
        self::assertSame(4, $this->wallet->claimDaily('alice', '2026-03-10'));
        self::assertSame(0, $this->wallet->claimDaily('alice', '2026-03-10'));

        $this->now = $this->now->modify('+1 day');
        self::assertSame(3, $this->wallet->claimDaily('alice', '2026-03-11'));

        // Skipped a day: streak restarts
        $this->now = $this->now->modify('+2 days');
        self::assertSame(4, $this->wallet->claimDaily('alice', '2026-03-13'));
        self::assertSame(1, $this->wallet->balance('alice')->dailyStreak);
    }

    public function testStreakWrapsAfterAFullWeek(): void
    {
        $total = 0;
        for ($i = 0; $i < 8; $i++) {
            $day = $this->now->format('Y-m-d');
            $total += $this->wallet->claimDaily('alice', $day);
            $this->now = $this->now->modify('+1 day');
        }
        self::assertSame(15 + 4, $total);
    }

    public function testDailyAcceptsTimezonesButNotTimeTravel(): void
    {
        // UTC is the 10th; a user in UTC+14 is already on the 11th
        self::assertSame(4, $this->wallet->claimDaily('alice', '2026-03-11'));
        // Moving the phone back a day does not earn again
        self::assertSame(0, $this->wallet->claimDaily('alice', '2026-03-10'));

        $this->expectException(\InvalidArgumentException::class);
        $this->wallet->claimDaily('alice', '2026-03-20');
    }

    public function testDailyRejectsMalformedDays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wallet->claimDaily('alice', '2026-02-30');
    }

    // ───────────── spending

    public function testSpendsFreeFirstAndRefundsToTheSameBuckets(): void
    {
        $this->wallet->open('alice', 'p1');                                    // 30 free
        $this->wallet->creditPurchases('alice', [new Purchase('tx', 'credits.small')]);   // 100 paid

        $receipt = $this->wallet->spend('alice', 50, 'generate', 'job-1');
        self::assertSame(['free' => 30, 'paid' => 20], $receipt);
        self::assertSame([0, 80], [$this->wallet->balance('alice')->free, $this->wallet->balance('alice')->paid]);

        $this->wallet->refund('alice', $receipt, 'job-1');
        $this->wallet->refund('alice', $receipt, 'job-1');   // a retried error handler
        self::assertSame([30, 100], [$this->wallet->balance('alice')->free, $this->wallet->balance('alice')->paid]);
    }

    public function testNotEnoughCreditsLeavesTheWalletAlone(): void
    {
        $this->wallet->open('alice', 'p1');
        try {
            $this->wallet->spend('alice', 31, 'generate');
            self::fail('expected NotEnoughCredits');
        } catch (NotEnoughCredits $e) {
            self::assertSame(30, $e->balance->total());
            self::assertSame(31, $e->needed);
        }
        self::assertSame(30, $this->wallet->balance('alice')->total());
        self::assertCount(1, $this->wallet->ledger('alice'));
    }

    public function testApprovalRewardOncePerContent(): void
    {
        self::assertSame(20, $this->wallet->creditApproval('alice', 'post-1'));
        self::assertSame(0, $this->wallet->creditApproval('alice', 'post-1'));
        self::assertSame(20, $this->wallet->creditApproval('alice', 'post-2'));
    }

    public function testVerifiedAdCallbackIsIdempotent(): void
    {
        self::assertSame(1, $this->wallet->creditAd('alice', 'ssv-123'));
        self::assertSame(0, $this->wallet->creditAd('alice', 'ssv-123'));
    }

    // ───────────── ledger

    public function testLedgerExplainsEveryCredit(): void
    {
        $this->wallet->open('alice', 'p1');
        $this->wallet->creditPurchases('alice', [new Purchase('tx', 'credits.small')]);
        $receipt = $this->wallet->spend('alice', 40, 'generate', 'job');
        $this->wallet->refund('alice', $receipt, 'job');

        $rows = $this->wallet->ledger('alice');
        self::assertSame(['welcome', 'purchase', 'generate', 'refund'], array_column($rows, 'reason'));

        // Replaying the ledger gives the balance: nothing changed it on the side
        $free = array_sum(array_column($rows, 'free'));
        $paid = array_sum(array_column($rows, 'paid'));
        $balance = $this->wallet->balance('alice');
        self::assertSame([$balance->free, $balance->paid], [$free, $paid]);
        self::assertSame(['free' => $free, 'paid' => $paid], end($rows)['after']);
    }

    // ───────────── accounts

    public function testMergeAnonymousIntoAccountOnce(): void
    {
        $this->wallet->open('anon', 'p1');                    // 30 free
        $this->wallet->claimDaily('anon', '2026-03-10');      // 4 paid
        $this->wallet->creditPurchases('account', [new Purchase('tx', 'credits.small')]);   // 100 paid

        $this->wallet->merge('account', 'anon');
        $this->wallet->merge('account', 'anon');

        $b = $this->wallet->balance('account');
        self::assertSame([30, 104], [$b->free, $b->paid]);
        self::assertSame('2026-03-10', $b->dailyLast);
        self::assertSame(0, $this->wallet->balance('anon')->total());
        self::assertSame('account', $this->store->get('wallets/anon')['merged_into']);
    }

    public function testDeleteKeepsClaimsSoBonusesCannotBeReEarned(): void
    {
        $this->wallet->open('alice', 'p1');
        $this->wallet->delete('alice');

        self::assertNull($this->store->get('wallets/alice'));
        self::assertSame([], $this->wallet->ledger('alice'));
        self::assertSame(0, $this->wallet->open('alice', 'p1')->total());
    }

    public function testRejectsPathInjectionInUid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wallet->balance('alice/ledger/x');
    }
}
