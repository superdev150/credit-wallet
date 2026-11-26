<?php

declare(strict_types=1);

namespace Showcase\Wallet;

/** Maps naturally to `402 not_enough_credits` with the balance, so the client can show the shop. */
final class NotEnoughCredits extends \RuntimeException
{
    public function __construct(public readonly Balance $balance, public readonly int $needed)
    {
        parent::__construct("Needs {$needed}, has {$balance->total()}");
    }
}
