<?php

declare(strict_types=1);

namespace Showcase\Wallet\Store;

/** @internal used by InMemoryStore */
final class InMemoryTransaction implements Transaction
{
    /** @var array<string, int> path → version seen (0 = did not exist) */
    public array $reads = [];

    /** @var array<string, array{0: 'set'|'create', 1: array}> */
    public array $writes = [];

    /** @param array<string, array{v: int, data: array}> $snapshot */
    public function __construct(private array $snapshot)
    {
    }

    public function get(string $path): ?array
    {
        if ($this->writes) {
            throw new \LogicException('Firestore transactions require all reads before any write');
        }
        $this->reads[$path] = $this->snapshot[$path]['v'] ?? 0;
        return $this->snapshot[$path]['data'] ?? null;
    }

    public function exists(string $path): bool
    {
        return $this->get($path) !== null;
    }

    public function set(string $path, array $data): void
    {
        $this->writes[$path] = ['set', $data];
    }

    public function create(string $path, array $data): void
    {
        $this->writes[$path] = ['create', $data];
    }
}
