<?php

declare(strict_types=1);

namespace Showcase\Wallet\Store;

/** The transaction kept colliding with concurrent writers and gave up. Safe to retry the whole operation. */
final class TransactionConflict extends \RuntimeException
{
}
