<?php

declare(strict_types=1);

namespace Showcase\Wallet\Store;

/**
 * Firestore rules, enforced by every implementation: all reads come before the first write, and `create` fails if the
 * document already exists.
 */
interface Transaction
{
    public function get(string $path): ?array;

    public function exists(string $path): bool;

    public function set(string $path, array $data): void;

    public function create(string $path, array $data): void;
}
