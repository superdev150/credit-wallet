<?php

declare(strict_types=1);

namespace Showcase\Wallet;

/** What the client sees. */
final class Balance implements \JsonSerializable
{
    public function __construct(
        public readonly int $free,
        public readonly int $paid,
        public readonly string $dailyLast = '',
        public readonly int $dailyStreak = 0,
    ) {
    }

    public static function of(?array $wallet): self
    {
        $w = $wallet ?? Wallet::blank();
        return new self((int) $w['free'], (int) $w['paid'], (string) ($w['daily']['last'] ?? ''), (int) ($w['daily']['streak'] ?? 0));
    }

    public function total(): int
    {
        return $this->free + $this->paid;
    }

    public function jsonSerialize(): array
    {
        return [
            'free' => $this->free,
            'paid' => $this->paid,
            'balance' => $this->total(),
            'daily' => ['last' => $this->dailyLast, 'streak' => $this->dailyStreak],
        ];
    }
}
