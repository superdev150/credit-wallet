<?php

declare(strict_types=1);

namespace Showcase\Wallet\Tests;

use PHPUnit\Framework\TestCase;
use Showcase\Wallet\Store\InMemoryStore;
use Showcase\Wallet\Store\Transaction;

final class InMemoryStoreTest extends TestCase
{
    public function testReadAfterWriteIsRejectedLikeFirestore(): void
    {
        $store = new InMemoryStore();
        $this->expectException(\LogicException::class);
        $store->transaction(function (Transaction $t) {
            $t->set('a/1', ['x' => 1]);
            $t->get('a/2');
        });
    }

    public function testCreateFailsIfTheDocumentExists(): void
    {
        $store = new InMemoryStore();
        $store->put('a/1', ['x' => 1]);
        $this->expectException(\LogicException::class);
        $store->transaction(fn (Transaction $t) => $t->create('a/1', ['x' => 2]));
    }

    public function testWritesAreAtomic(): void
    {
        $store = new InMemoryStore();
        try {
            $store->transaction(function (Transaction $t) {
                $t->set('a/1', ['x' => 1]);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        self::assertNull($store->get('a/1'));
    }

    public function testListIsShallow(): void
    {
        $store = new InMemoryStore();
        $store->put('w/1', []);
        $store->put('w/1/ledger/a', ['n' => 1]);
        $store->put('w/1/ledger/b', ['n' => 2]);
        $store->put('w/1/ledger/b/deeper/c', []);

        self::assertSame(['w/1/ledger/a', 'w/1/ledger/b'], array_column($store->list('w/1/ledger'), 'path'));
    }
}
