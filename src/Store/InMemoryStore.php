<?php

declare(strict_types=1);

namespace Showcase\Wallet\Store;

/**
 * Optimistic-concurrency store that behaves like Firestore where it matters: every document has a version, a
 * transaction remembers the version of everything it read, and commit fails (then retries `$fn`) if any of them
 * changed in between.
 *
 * `interleave()` lets a test run another writer between a transaction's reads and its commit, which is the race that
 * makes double-crediting possible in naive code.
 */
final class InMemoryStore implements Store
{
    public const MAX_ATTEMPTS = 5;

    /** @var array<string, array{v: int, data: array}> */
    private array $docs = [];

    /** @var list<\Closure(self): void> */
    private array $interleaved = [];

    public int $attempts = 0;
    public int $commits = 0;

    /** Run `$writer` right before the next commit attempt (once). */
    public function interleave(\Closure $writer): void
    {
        $this->interleaved[] = $writer;
    }

    public function transaction(callable $fn): mixed
    {
        for ($try = 1; $try <= self::MAX_ATTEMPTS; $try++) {
            $this->attempts++;
            $tx = new InMemoryTransaction($this->docs);
            $result = $fn($tx);

            if ($this->interleaved) {
                (array_shift($this->interleaved))($this);
            }

            foreach ($tx->reads as $path => $version) {
                if (($this->docs[$path]['v'] ?? 0) !== $version) {
                    continue 2;   // someone wrote what we read: run $fn again on fresh data
                }
            }
            foreach ($tx->writes as $path => [$mode, $data]) {
                if ($mode === 'create' && isset($this->docs[$path])) {
                    throw new \LogicException("create: {$path} already exists");
                }
            }
            foreach ($tx->writes as $path => [, $data]) {
                $this->docs[$path] = ['v' => ($this->docs[$path]['v'] ?? 0) + 1, 'data' => $data];
            }
            $this->commits++;
            return $result;
        }

        throw new TransactionConflict('Gave up after ' . self::MAX_ATTEMPTS . ' attempts');
    }

    public function get(string $path): ?array
    {
        return $this->docs[$path]['data'] ?? null;
    }

    public function list(string $collection): array
    {
        $prefix = rtrim($collection, '/') . '/';
        $out = [];
        foreach ($this->docs as $path => $doc) {
            if (str_starts_with($path, $prefix) && !str_contains(substr($path, strlen($prefix)), '/')) {
                $out[] = ['path' => $path, 'data' => $doc['data']];
            }
        }
        return $out;
    }

    public function delete(string $path): void
    {
        unset($this->docs[$path]);
    }

    /** Write outside a transaction (bumps the version, like any other writer). */
    public function put(string $path, array $data): void
    {
        $this->docs[$path] = ['v' => ($this->docs[$path]['v'] ?? 0) + 1, 'data' => $data];
    }
}
