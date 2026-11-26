<?php

declare(strict_types=1);

namespace Showcase\Wallet\Store;

use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction as FsTransaction;

/**
 * Store backed by Cloud Firestore (`composer require google/cloud-firestore`). Paths are Firestore document paths,
 * e.g. "wallets/uid-1" or "wallets/uid-1/ledger/abc".
 *
 * Firestore itself retries the transaction when it collides with another writer, so this adapter is thin.
 * Not exercised in CI (it needs a project or the emulator); InMemoryStore implements the same semantics.
 */
final class FirestoreStore implements Store
{
    public function __construct(private FirestoreClient $db)
    {
    }

    public function transaction(callable $fn): mixed
    {
        return $this->db->runTransaction(function (FsTransaction $t) use ($fn) {
            return $fn(new class ($this->db, $t) implements Transaction {
                public function __construct(private FirestoreClient $db, private FsTransaction $t)
                {
                }

                public function get(string $path): ?array
                {
                    $snap = $this->t->snapshot($this->db->document($path));
                    return $snap->exists() ? $snap->data() : null;
                }

                public function exists(string $path): bool
                {
                    return $this->t->snapshot($this->db->document($path))->exists();
                }

                public function set(string $path, array $data): void
                {
                    $this->t->set($this->db->document($path), $data);
                }

                public function create(string $path, array $data): void
                {
                    $this->t->create($this->db->document($path), $data);
                }
            });
        }, ['maxRetries' => InMemoryStore::MAX_ATTEMPTS]);
    }

    public function get(string $path): ?array
    {
        $snap = $this->db->document($path)->snapshot();
        return $snap->exists() ? $snap->data() : null;
    }

    public function list(string $collection): array
    {
        $out = [];
        foreach ($this->db->collection($collection)->documents() as $snap) {
            $out[] = ['path' => $snap->reference()->path(), 'data' => $snap->data()];
        }
        return $out;
    }

    public function delete(string $path): void
    {
        $this->db->document($path)->delete();
    }
}
