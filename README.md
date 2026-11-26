<img src="https://img.shields.io/badge/php-8.2%2B-777bb4" alt="PHP 8.2+"> <img src="https://img.shields.io/badge/deps-none-brightgreen" alt="no runtime dependencies">

# credit-wallet

A **server-side virtual-currency wallet**: credits from purchases, subscriptions, daily rewards and ads, spent on paid
features. Every change runs in a transaction, every credit is in an append-only ledger, and anything that may be granted
only once is a **claim**, so retries, webhooks and races cannot pay twice.

```php
$wallet = new Wallet(new FirestoreStore($firestore));          // or new InMemoryStore() in tests

$wallet->open($uid, $deviceId);                                // welcome bonus, once per device
$wallet->creditPurchases($uid, $purchasesFromBilling);         // safe to call on every app start
$receipt = $wallet->spend($uid, 30, 'generate', $jobId);       // throws NotEnoughCredits
$wallet->refund($uid, $receipt, $jobId);                       // provider failed: at most once
```

> Taken from the backend of [Wallstudio](https://apps.apple.com/app/id6757434029), my iOS/Android wallpaper app, where
> each AI image costs real money at the provider. The app used to keep credits on the phone; moving them to the server
> (with a one-time import) is what this code does.

## Why it is built this way

| Problem | What the wallet does |
|---|---|
| The client can be modified | the balance lives on the server; the client only asks ("check my purchases") and never sends amounts |
| The same purchase arrives twice: app + webhook, restore on another account | `claims/sha256("purchase:<store tx id>")` is created **in the same transaction** as the credit. The second request sees it and adds 0, whoever sends it |
| Two paid jobs start at once on a balance that covers one | `spend()` runs in a transaction *before* the expensive call. The loser retries, sees the new balance and gets `NotEnoughCredits` |
| The paid call fails after charging | `refund()` returns the exact buckets taken and is itself a claim (`refund:<ref>`), so a retried error handler refunds once |
| "Sign out → new account → welcome bonus again" | the welcome is claimed per **device**, not per account |
| Deleting the account to farm one-time bonuses | wallet and ledger are deleted, claims are kept |
| Free bonus credits pile up forever | two buckets: `free` (bonuses, low cap, spent first) and `paid` (purchases, rewards) |
| Moving the phone's clock to farm the daily reward | the local date must be within ±1 day of UTC **and** later than the last claimed day |
| "Where did these credits come from?" | each change writes a ledger row with the delta and the balance after it. Replaying the ledger gives the balance, and a test checks that |

## Transactions, the Firestore way

`Store` + `Transaction` are two small interfaces that describe what Firestore offers: serializable transactions where **all reads
come before any write**, `create` fails if the document exists, and the whole function is **re-run** when a document it
read changed before commit.

`InMemoryStore` implements those semantics with document versions, so the tests show real races. `interleave()` runs a
second request between the first one's reads and its commit:

```php
$store->interleave(fn () => $wallet->creditPurchases('alice', [$tx1]));   // the webhook
$wallet->creditPurchases('alice', [$tx1]);                                 // the app, at the same time
// → credited once; the app's transaction retried and saw the claim
```

The tests were checked by breaking them on purpose: with conflict detection switched off in the store, all 6
concurrency tests fail.

`FirestoreStore` is the production adapter (`google/cloud-firestore`). It is short because Firestore already
does the hard part.

## Data model

```
wallets/{uid}              { free, paid, daily {last, streak}, sub {welcomed, last}, merged_into?, created_at, updated_at }
wallets/{uid}/ledger/{id}  { reason, free, paid, ref, after {free, paid}, at }
claims/{sha256(key)}       { key, uid, at }
```

Claim keys: `welcome:dev:<device>`, `import:dev:<device>`, `purchase:<tx>`, `sub:<subscription>:<period>`,
`approval:<content>`, `ad:<verified callback id>`, `refund:<ref>`. They are hashed because store ids may contain `/`
and the raw device ids should not be stored.

All amounts are in `Economy`. The defaults are examples. In production I set them from unit costs: what one AI
generation costs at the provider, what a rewarded ad really pays in each region, and the store's commission. That way
every source of credits stays profitable.

## Run it

```bash
composer install
composer test        # 32 tests, no network, ~20 ms
```

`examples/` shows the wallet behind a paid endpoint (charge → call → refund on failure) and a mapper from RevenueCat's
REST API to `Purchase` / `Subscription`.

MIT licensed.
