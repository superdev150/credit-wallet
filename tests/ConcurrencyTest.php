<?php

declare(strict_types=1);

namespace Showcase\Wallet\Tests;

use PHPUnit\Framework\TestCase;
use Showcase\Wallet\Economy;
use Showcase\Wallet\NotEnoughCredits;
use Showcase\Wallet\Purchase;
use Showcase\Wallet\Store\InMemoryStore;
use Showcase\Wallet\Store\TransactionConflict;
use Showcase\Wallet\Wallet;

/**
 * The races that break naive "read balance, add, write balance" code. Each test slips a second request in between the
 * first one's reads and its commit.
 */
final class ConcurrencyTest extends TestCase
{
    private InMemoryStore $store;
    private Wallet $wallet;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->wallet = new Wallet($this->store, new Economy());
    }

    public function testTheSamePurchaseReportedTwiceAtOnceIsCreditedOnce(): void
    {
        // The app reports the purchase and, at the same moment, a webhook does too
        $this->store->interleave(function () {
            self::assertSame(100, $this->wallet->creditPurchases('alice', [new Purchase('tx-1', 'credits.small')]));
        });

        $second = $this->wallet->creditPurchases('alice', [new Purchase('tx-1', 'credits.small')]);

        self::assertSame(0, $second);   // on retry it saw the claim
        self::assertSame(100, $this->wallet->balance('alice')->paid);
        self::assertCount(1, $this->wallet->ledger('alice'));
    }

    public function testParallelSpendsCannotOverdraw(): void
    {
        $this->wallet->open('alice', 'p1');   // 30

        // Two 30-credit jobs start together; only one may be paid for
        $this->store->interleave(function () {
            $this->wallet->spend('alice', 30, 'generate', 'job-a');
        });

        $this->expectException(NotEnoughCredits::class);
        try {
            $this->wallet->spend('alice', 30, 'generate', 'job-b');
        } finally {
            self::assertSame(0, $this->wallet->balance('alice')->total());
        }
    }

    public function testConcurrentCreditsAreNotLost(): void
    {
        $this->wallet->open('alice', 'p1');   // 30
        $this->store->interleave(function () {
            $this->wallet->creditApproval('alice', 'post-1');   // +20 lands between our read and our write
        });

        $this->wallet->claimDaily('alice', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d'));   // +4

        self::assertSame(54, $this->wallet->balance('alice')->total());
    }

    public function testWelcomeOnTwoAccountsOfTheSameDeviceAtOnce(): void
    {
        $this->store->interleave(function () {
            $this->wallet->open('account-a', 'phone-1');
        });
        $this->wallet->open('account-b', 'phone-1');

        $total = $this->wallet->balance('account-a')->total() + $this->wallet->balance('account-b')->total();
        self::assertSame(30, $total);
    }

    public function testGivesUpAfterTooManyConflicts(): void
    {
        for ($i = 0; $i < InMemoryStore::MAX_ATTEMPTS; $i++) {
            $this->store->interleave(function () use ($i) {
                $this->store->put('wallets/alice', Wallet::blank() + ['n' => $i]);
            });
        }
        $this->expectException(TransactionConflict::class);
        $this->wallet->creditApproval('alice', 'post-1');
    }

    public function testRetriedWorkDoesNotDoubleCountReturnValues(): void
    {
        $this->store->interleave(function () {
            $this->store->put('wallets/alice', Wallet::blank());   // unrelated write forces a retry
        });
        self::assertSame(300, $this->wallet->creditPurchases('alice', [new Purchase('tx-1', 'credits.medium')]));
        self::assertGreaterThan($this->store->commits, $this->store->attempts);
    }
}
