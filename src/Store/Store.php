<?php

declare(strict_types=1);

namespace Showcase\Wallet\Store;

/**
 * A document store with serializable transactions: the contract Firestore gives you, and the only one the wallet needs.
 *
 * `transaction($fn)` runs `$fn(Transaction)` and commits its writes atomically. If another writer changed a document
 * that `$fn` read, the store runs `$fn` again from scratch. So `$fn` must have no side effects besides the transaction.
 */
interface Store
{
    /**
     * @template T
     * @param callable(Transaction): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed;

    /** Plain read, outside any transaction. */
    public function get(string $path): ?array;

    /** @return list<array{path: string, data: array}> documents directly under a collection path */
    public function list(string $collection): array;

    public function delete(string $path): void;
}
