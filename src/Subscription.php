<?php

declare(strict_types=1);

namespace Showcase\Wallet;

/** An active (or not) subscription as the billing provider reports it. */
final class Subscription
{
    /** @param 'monthly'|'annual' $plan */
    public function __construct(
        public readonly string $id,
        public readonly string $plan,
        public readonly bool $active,
    ) {
        if (!in_array($plan, ['monthly', 'annual'], true)) {
            throw new \InvalidArgumentException("Unknown plan {$plan}");
        }
    }

    /** One grant per calendar month (monthly) or year (annual). */
    public function periodOf(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format($this->plan === 'annual' ? 'Y' : 'Y-m');
    }
}
