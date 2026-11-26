<?php

declare(strict_types=1);

namespace Showcase\Wallet;

/**
 * Every number that defines the economy, in one place. The defaults are examples: in my apps they come from the cost of
 * one AI generation and what a rewarded ad actually pays, so that each source of credits stays profitable.
 */
final class Economy
{
    /**
     * @param list<int> $dailyRewards reward for day 1, 2, … of a streak (wraps around)
     * @param array<string, int> $planBonus first period of a plan, by plan kind
     * @param array<string, int> $planRenewal every later period
     * @param array<string, int> $packs store product id → credits
     */
    public function __construct(
        public readonly int $welcome = 30,
        public readonly array $dailyRewards = [4, 3, 2, 2, 2, 1, 1],
        public readonly int $perAd = 1,
        public readonly int $approvalReward = 20,
        public readonly array $planBonus = ['monthly' => 100, 'annual' => 150],
        public readonly array $planRenewal = ['monthly' => 80, 'annual' => 120],
        public readonly int $maxFree = 200,
        public readonly int $maxPaid = 9999,
        public readonly array $packs = ['credits.small' => 100, 'credits.medium' => 300, 'credits.large' => 500],
    ) {
        if ($dailyRewards === []) {
            throw new \InvalidArgumentException('dailyRewards cannot be empty');
        }
    }
}
