# Forgeline x Sellaxis -- Ingestion Slice (Part C)

A standalone PHP service implementing the hardest vertical slice of the
Forgeline/Sellaxis marketplace integration: **event ingestion -> validation
-> normalisation -> persistence -> idempotent processing -> order/line state
tracking -> a reconciliation/exception API.**

This is deliberately not a CRUD demo. Every piece of this exists because one
of the sixteen stub failure cases would break it otherwise. See the
[Decision Log](#decision-log-the-hardest-calls) for the reasoning, and
[`docs/CASES.md`](docs/CASES.md) for a one-line-per-case account of exactly
how each of the sixteen is handled (or, for the one not owned by this slice,
how I'd handle it).

## Why this slice, and not another one

Of everything the brief's pipeline could test, **order/line state under
at-least-once, out-of-order delivery** is where money, oversell, and data
corruption all converge -- it's the one piece that, if wrong, breaks every
other part of the system silently. Inventory versioning is a close second
and is included because the infrastructure (dedup, the version-checked
write) is shared with the order path. I went deep on these two plus the
payment-capture/ERP-outbox path (case 16 / case 4) rather than wide on
shallow CRUD, per the brief's own instruction.

## Quick start

```bash
docker compose up --build
```

This brings up:

| Service | Purpose |
|---|---|
| `db` | Postgres 16, schema loaded automatically from `migrations/` |
| `fake-sellaxis` | A trivial local fake for Sellaxis's poll API **and** the Operator ERP -- there is no real Sellaxis, per the stub's own instruction to wrap the canned payloads in a fake |
| `app` | The main HTTP service (webhook endpoint, orders API, reconciliation API) on `:8080` |
| `seed` | Runs once, loads the section-8 fixture (6 sellers, sample offers, mappings) |
| `poller` | Polls the fake Sellaxis on a 90s cycle (above the documented 60s floor) -- the backfill safety net, not the primary path |
| `outbox-relay` | Delivers pending ERP outbox rows on a 15s cycle |

Then:

```bash
docker compose run --rm app composer install   # first time only, inside the container
docker compose run --rm app vendor/bin/phpunit  # runs the real test suite
```

### Try it by hand

```bash
# A normal order (case 1), signed correctly:
BODY='{"delivery_id":"dlv_demo1","event_id":"evt_demo1","event_type":"order.created","occurred_at":"2026-06-12T23:50:11+05:30","data":{"order_ref":"ORD-IN-900001","market":"IN","currency":"INR","buyer_ref":"BUY-IN-1","created_at":"2026-06-12T23:50:11+05:30","lines":[{"line_ref":"L1","seller_id":"SLR-1001","seller_sku":"FAST-M8-100","qty":5,"unit_price":"12.40","currency":"INR"}]}}'
SIG=$(echo -n "$BODY" | openssl dgst -sha256 -hmac "dev-secret" | sed 's/^.* //')
curl -X POST http://localhost:8080/webhooks/sellaxis \
  -H "X-Sellaxis-Signature: sha256=$SIG" -H "Content-Type: application/json" \
  -d "$BODY"

# Reconciliation / exception views:
curl http://localhost:8080/reconciliation/summary
curl http://localhost:8080/reconciliation/exceptions
curl http://localhost:8080/reconciliation/stuck-orders?older_than_minutes=60
curl http://localhost:8080/reconciliation/outbox

# Tenant-scoped order read (case 14):
curl http://localhost:8080/api/orders/ORD-IN-900001 -H "Authorization: Bearer token-slr-1001"   # 200
curl http://localhost:8080/api/orders/ORD-IN-900001 -H "Authorization: Bearer token-slr-2001"   # 403
```

## Architecture in one paragraph

A webhook lands on `POST /webhooks/sellaxis`, gets its HMAC signature
verified before anything else touches it, and is durably recorded in an
**inbox table** (`inbound_events`, unique on `delivery_id`) before being
acknowledged -- this single table does dedup (case 2/13), out-of-order
holding (case 3), and malformed-item quarantine (case 10), because all
three are really the same underlying question: *what do we do with an
event we can't apply immediately, and how do we never lose track of it.*
A small **state machine** (`LineStateMachine`) governs every line's
transitions; an event that doesn't match the line's current state is held,
not force-applied or dropped, and every successful transition re-checks
held events for that order. Inventory writes go through one **version-
checked conditional update** (`InventoryVersionGuard`) regardless of
whether the update came from a webhook, a poll, or the nightly snapshot --
this single rule is the entire fix for cases 11 and 12. Outbound ERP calls
go through an **outbox** (`outbox_events`), delivered by a separate relay,
never inline in the request path -- this is what makes case 4's
"commits-then-times-out" survivable.

```
Sellaxis (fake) --webhook--> [signature check] --> inbox (dedup) --> ack 2xx
                                                       |
                                                       v
                                      OrderProcessor (state machine,
                                      mapping resolution, inventory
                                      version guard, outbox enqueue)
                                                       |
                                                       v
                                       Postgres (orders, lines, inventory,
                                       outbox, inbox -- all one DB)
                                                       ^
                                                       |
                              poller (60s-floor-respecting backfill)
                              outbox-relay (ERP delivery, idempotency-key
                              aware, confirms-before-retry on timeout)
```

## How this would integrate into Magento 2 / Adobe Commerce

This was built standalone (the brief's explicitly allowed option) because a
bare Magento module skeleton with no Magento runtime to execute it against
would be theatre -- I'd rather hand over real, runnable, tested logic than
an empty `etc/di.xml`. Here is exactly where each piece would live if
ported:

| This slice | Magento 2 / Adobe Commerce equivalent |
|---|---|
| `WebhookController` (`public/index.php` route) | A Magento **controller** under a custom module's `Controller/Webhook/Index.php`, registered via `routes.xml`. Same signature-check-then-durable-write logic, unchanged. |
| `InboundEventRepository`, `OrderRepository`, `InventoryRepository`, `OutboxRepository` | Magento **resource models + repositories** (`Model/ResourceModel/*`, implementing repository interfaces), backed by the same schema via `db_schema.xml` instead of raw SQL migrations. The SQL itself barely changes. |
| `OrderProcessor::handleOrderCreated` | A **queue consumer** (`etc/queue_consumer.xml` + a `Consumer` class), since this is exactly Magento's own pattern for asynchronous webhook-driven work. The webhook controller would publish to a topic; the consumer would call the same processing logic. |
| `InventoryRepository::applyIfNewer` (the version-checked write) | Still calls into Magento's **MSI** (`StockItemRepositoryInterface` / source-item save), but wrapped in the same version-guard logic -- this is the one place I'd insist the version check sits *in front of* the MSI call, not inside a raw `StockRegistryInterface::updateStockItemBySku`, for exactly the reasons in the Part B review (Finding C3). |
| `SellerProductMapRepository` | A custom mapping table (`forgeline_seller_product_map`), exposed through a repository, used by an **observer** on `sales_order_save_before` or, better, inside the same queue consumer before the Magento order is created -- so an unmapped SKU is caught before a Magento order line ever references a nonexistent product. |
| `OrdersController` (tenant isolation) | A Magento **REST API** module (`webapi.xml`) with an ACL resource scoped per seller, or -- if Forgeline's seller portal is a separate storefront -- middleware that resolves `seller_id` from the **customer session / integration token**, never from a query parameter. Same rule, different transport. |
| `ErpClient` / `OutboxRepository` | A **plugin (interceptor)** on whatever triggers ERP sync today, rewritten to write an outbox row instead of calling out inline, plus a small **cron job** (`crontab.xml`) standing in for the `outbox-relay` console command. |
| `PollSellaxis.php` | A Magento **cron job**, same 60s-floor-respecting logic, calling the same `SellaxisClient`. |
| `Money` (bcmath decimal handling) | Mirrors what Magento's own pricing/currency utilities already try to do -- the discipline (never float, always string-in/string-out) is the same; this class would likely become a thin wrapper if Magento's own utilities are deemed sufficient, kept separate only if Sellaxis's decimal-string format needs an explicit boundary before it touches Magento's own money types. |

The state machine, the version-guard rule, and the inbox/outbox pattern are
**the same code regardless of host** -- they're plain PHP domain logic with
no Magento dependency, which is exactly why they're written the way they
are here. Porting is mechanically wiring Magento's DI/queue/cron framework
around them, not rewriting the logic itself.

## Verification note (how I actually proved this works)

This authoring sandbox's network egress is restricted to a fixed allowlist
that does not include Packagist or `phar.phpunit.de`, so I could not run
`composer install` here to pull real PHPUnit. Rather than ship untested
code, I installed PHP 8.3 + Postgres 16 directly in this environment, wrote
a tiny (~150-line) PHPUnit-compatible `TestCase` shim implementing only the
assertion methods this suite actually uses, and ran the **exact same test
files in `tests/`, completely unmodified**, against a real Postgres
database -- not a mock, not an in-memory fake.

**Result: 26 tests, 72 assertions, 0 failures, 0 errors**, including the
full out-of-order recheck cascade (case 3), partial rejection (case 7),
cancellation-after-shipment routing to refund instead of overwriting
shipped state (case 9), inventory version guarding under simulated
snapshot/incremental overlap (case 11/12), and the capture-then-
persistence-failure recovery path (case 16).

I then went further and ran the **real HTTP service** (PHP's built-in
server under `public/router.php`, the same entry point `docker compose`
uses) end to end against a signed webhook request, and that surfaced a
real bug the domain-layer suite couldn't have caught: `Logger` referenced
the `STDOUT` constant, which is only defined under the plain CLI SAPI --
under `cli-server` (and most web SAPIs), it doesn't exist, so every single
log call fatally crashed the request. Fixed by switching to
`fopen('php://stdout', 'w')`, which is portable across SAPIs. Re-verified
afterward, in one uninterrupted process lifetime: health check, a signed
`order.created` webhook (`200`, correct `order_created` outcome, correct
structured log line, server still alive after), and the case-14
cross-seller-access check (owning seller gets `200`, a different seller
gets `403`, no auth gets `401`) all confirmed against the live server, not
just the domain layer.

That bug, and the process of finding it, is the concrete argument for
"what I'd do next" item #1 below -- the domain-layer suite was genuinely
green while a real crash sat one layer up, because nothing exercised that
layer. I'm leaving that gap explicitly open rather than backfilling a
testing-the-testers narrative after the fact.

In any environment with normal internet access -- including the
`docker compose` stack above -- `composer install` resolves real
`phpunit/phpunit`, and `vendor/bin/phpunit` runs these identical domain-
layer test files against the real framework. The shim is not part of the
shipped application; it only exists because I wanted to *prove* green
tests rather than assert them.

## Assumptions

1. **Capture timing.** The stub doesn't fully specify whether "captured at
   placement" means at order-arrival or after line acceptance completes,
   given two-phase acceptance. I modelled capture as happening at
   order-creation time (when `order.created` is first processed), inside
   the same transaction as the order/line insert -- see Decision D1 below.
   If Forgeline's actual rule is "capture only after all lines are
   decided," the capture call moves to the confirmation step; the
   outbox/recovery mechanics around it are unchanged either way.
2. **FX.** Per the stub's explicit field note, there is no FX rate in any
   feed. This slice never converts between INR and EUR -- `Money::add()`
   throws if currencies differ, on purpose (see Decision D3).
3. **Seller auth.** No real auth system exists in the stubs, so a minimal
   bearer-token-to-seller_id table (`sellers.portal_token`) stands in for
   whatever Forgeline's real seller portal auth is. The point being tested
   (case 14) is the *authorization rule*, not the token mechanism.
4. **ShipBridge.** Only referenced in comments and the Magento mapping
   table -- not implemented, since none of the sixteen cases this slice
   owns require it. The ERP outbox pattern this slice does implement is
   the template for ShipBridge label calls too.
5. **Case 15 (configurable vs. simple SKU)** is explained, not built -- see
   `docs/CASES.md`. It's fundamentally about Magento's MSI/EAV catalogue
   structure (stock on the child, not the parent), which has no honest
   standalone-PHP equivalent to test without a real Magento catalogue.

## Decision Log -- the hardest calls

**D1 -- Capture happens inside the same transaction as order creation, not
before it, and not asynchronously after it.**
Part B's review of the prior draft flagged capture-before-order-exists as a
severe, real-money risk (orphaned charges with no order record). The
opposite extreme -- capture asynchronously, after the order safely commits
-- reopens a different gap: an order with no payment behind it. Putting
both in one transaction means there's no SQL-visible state where one exists
without the other... except for the external gateway call itself, which a
`ROLLBACK` can't undo. That residual gap is case 16, and it's exactly why
`reconcileOrphanedCapture()` exists: the design doesn't pretend the gap can
be closed to zero, it makes sure the gap is *always recoverable* rather
than silently logged. I considered a pure outbox-for-capture-too design
(never call the gateway inline at all) and rejected it for v1 because
payment capture typically needs a synchronous response in the actual
checkout flow -- unlike the ERP call, which has no one waiting on it.

**D2 -- One version-checked write rule for inventory, with no special case
for the nightly snapshot.**
The alternative (treat the snapshot as authoritative, since it's "the full
picture") is intuitive and wrong: it would let a snapshot generated hours
ago silently overwrite a real-time update that landed since. Making every
write source equal under one comparison rule sounds almost too simple for
how much it resolves (cases 11 and 12 both vanish as separate problems),
which is exactly why it's worth stating as a deliberate decision rather
than something that fell out of the schema by accident.

**D3 -- Refuse to combine currencies; never invent an FX rate.**
The tempting alternative -- pick a rate source and pin it, so cross-
currency totals "just work" -- is explicitly what the stub warns against
doing silently. `Money::add()` throwing on a currency mismatch means any
future code that tries to sum INR and EUR fails loudly, immediately, at
the first attempt, rather than producing a wrong number that looks
plausible. This costs nothing now (this slice never needs to combine
currencies) and prevents a real mistake later when someone adds a "total
across all markets" feature without thinking about FX.

**D4 -- Held events are re-checked by event, not by polling a queue.**
The out-of-order mechanism (case 3) needed a recheck trigger. The
alternative -- a periodic sweep that re-tries all held events every N
seconds -- is simpler to write but adds latency (the held event sits there
until the next sweep) and load (re-checking events that have no reason to
have changed). Re-checking immediately after the specific transition that
might unblock them is more code (the recursive recheck call inside
`dispatch`) but resolves the held event in the same request that unblocked
it, with no polling delay and no wasted re-checks of unrelated orders.

**D5 -- `Logger` writes to `php://stdout`, not the `STDOUT` constant.**
Not a design decision so much as a bug worth documenting precisely because
of how it was found. The `STDOUT` constant is only defined under the CLI
SAPI; every log call (which is most code paths) fatally crashed under
`cli-server` -- meaning every domain-layer test passed (they run via plain
`php` CLI) while the real HTTP service crashed on its first real request.
I only caught this by manually running the actual webhook endpoint end to
end rather than stopping at green domain tests. It's documented here, not
buried in a changelog, because it's the single best argument in this whole
project for why "the tests pass" and "the system works" are different
claims, and why item #1 in "what I'd do next" matters more than it might
look like on a first read of this README.

## Where I stopped / what I'd do next

**Stopped at:** 14 of the 16 cases implemented and test-proven against real
Postgres (cases 1-14 and 16; case 15 explained-not-built per the table
above and `docs/CASES.md`). The automated suite covers the
*domain/repository* layer directly; the HTTP layer (`WebhookController`,
`OrdersController`, `ReconciliationController`) was verified manually,
end to end, against the real running server (see "Verification note"
above) -- including catching and fixing a real SAPI-portability bug in
`Logger` that the domain-layer suite couldn't have caught. What's still
missing is that manual verification turned into **automated** HTTP-level
tests that run on every change, not just once by hand.

**What I'd do next, in order:**
1. Turn the manual HTTP verification above into automated end-to-end tests
   that start the real `cli-server`-SAPI service and hit it over HTTP --
   not because I doubt the controllers now, but because "I checked it by
   hand once" is exactly the gap that let the `Logger`/`STDOUT` bug ship
   in the first place, and a regression in either direction (a future
   change reintroducing a SAPI assumption, or breaking the webhook path
   some other way) deserves the same kind of proof the domain layer has.
2. A real idempotency key on the **gateway capture call itself** (right
   now `createOrderWithCapture`'s capture closure is a clean injection
   point for tests, but a production version needs the same idempotency-
   key discipline `ErpClient` already has, so a retried capture after a
   timeout doesn't double-charge).
3. A dedicated test proving the `inventory.changed` **webhook** path
   specifically end to end (the validator checks its shape, the dispatch
   path handles it, and the underlying version-guard is proven in
   `Case11And12InventoryVersionTest` -- what's missing is one test that
   goes through `OrderProcessor::processEvent` with a webhook-shaped
   inventory event rather than calling the repository directly).
4. A proper migrations tool (the schema currently ships as one
   `001_init.sql` run once by Postgres's own init mechanism -- fine for
   this exercise, not how I'd manage schema changes over time).
5. Replace the bearer-token-to-seller_id stand-in with whatever
   Forgeline's real seller portal auth actually is, once that's known.
6. Case 15, for real, against an actual Magento MSI-backed catalogue, once
   one exists to test against.
7. An audit pass for other CLI-SAPI assumptions in the codebase, prompted
   directly by finding the `Logger`/`STDOUT` bug -- that bug's root cause
   (code written and tested under CLI, deployed under a different SAPI)
   is a pattern worth checking for elsewhere, not just patching the one
   instance found.

## Project layout

```
src/
  Domain/      -- pure logic: state machine, version guard, validator, Money
  Infra/       -- Postgres repositories, the inbox/outbox, ERP/Sellaxis clients
  Http/        -- controllers (webhook, orders, reconciliation), router
  Console/     -- poller, outbox relay, seed script
fake-sellaxis/ -- the trivial local fake for Sellaxis + ERP
migrations/    -- schema (one file; see "what I'd do next" #4)
tests/         -- one file per stub case (or pair of related cases), plus
                  unit tests for Money and signature verification
docs/CASES.md  -- one line per stub case: owned/explained, and where
```
