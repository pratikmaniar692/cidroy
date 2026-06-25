# The Sixteen Cases -- One Line Each

Owned (implemented + test-proven against real Postgres) unless marked
otherwise. "Where" points at the file with the actual handling logic;
"Test" points at the proof.

| # | Case | Owned? | Where | Test |
|---|---|---|---|---|
| 1 | Normal order | Yes | `OrderProcessor::handleOrderCreated` | `Case01And02NormalAndDuplicateTest::test_normal_order_is_created_with_mapped_line` |
| 2 | Duplicate webhook, different delivery_id | Yes | `InboundEventRepository::recordDelivery` (unique constraint on delivery_id) + `OrderProcessor::processEvent` alreadyProcessed check | `Case01And02NormalAndDuplicateTest::test_duplicate_delivery_id_does_not_create_two_orders` |
| 3 | Out-of-order status (shipped before accepted) | Yes | `LineStateMachine::check` (validity) + `OrderProcessor::handleLineTransition` / `recheckHeldEvents` (hold-and-retry) | `Case03OutOfOrderTest::test_shipped_before_accepted_is_held_then_applied_in_correct_order` |
| 4 | ERP times out after success; retry repeats | Yes | `ErpClient::postPurchaseOrder` (confirm-before-retry via the same idempotency key) + `OutboxRepository` + `Console/ProcessOutbox.php` | Exercised live via `fake-sellaxis/server.php`'s `?simulate_timeout=1`; not yet in the automated suite -- see README "what I'd do next" #1 (this is an HTTP-timing case, best proven end-to-end rather than at the repository layer) |
| 5 | Missing seller-product mapping | Yes | `SellerProductMapRepository::resolve` (offer exists, no map row -> missing_mapping) | `Case05And06MappingTest::test_missing_mapping_creates_order_but_flags_the_line` |
| 6 | Unknown SKU system-wide | Yes | `SellerProductMapRepository::resolve` (no offer on record at all -> unknown_sku) | `Case05And06MappingTest::test_unknown_sku_with_no_offer_on_record_is_also_created_but_distinctly_flagged` |
| 7 | Multi-seller order, partial rejection | Yes | Per-line state machine (no order-level lock-step); `OrderRepository::deriveOrderState` returns mixed | `Case07PartialRejectionTest::test_one_seller_refuses_while_another_accepts` |
| 8 | Cancellation after reservation | Yes | `OrderProcessor::handleCancellation` (clean transition to cancelled) | `Case08And09CancellationTest::test_cancellation_after_acceptance_before_shipment_cancels_cleanly` |
| 9 | Cancellation after shipment | Yes | `OrderProcessor::handleCancellation` + `LineStateMachine::cancellationRequiresReturnFlow` (routes to refund, never overwrites shipped) | `Case08And09CancellationTest::test_cancellation_after_shipment_does_not_overwrite_shipped_state` |
| 10 | Malformed item in a valid batch | Yes | `EventValidator::validateLine` (per-line, independent) + `WebhookController::handleOneEvent` (per-item quarantine) | `Case10MalformedItemTest` |
| 11 | Stale inventory update (older version) | Yes | `InventoryVersionGuard::shouldApply` + `InventoryRepository::applyIfNewer` | `Case11And12InventoryVersionTest::test_stale_update_with_lower_version_is_discarded` |
| 12 | Full snapshot overlapping incrementals | Yes | Same mechanism as #11 -- deliberately, see Decision D2 in README | `Case11And12InventoryVersionTest::test_full_snapshot_with_older_version_does_not_clobber_newer_incremental` |
| 13 | Retry of an already-completed event | Yes | `InboundEventRepository::alreadyProcessed` (keyed on event_id, stable across redeliveries) | `Case01And02NormalAndDuplicateTest::test_duplicate_delivery_id_does_not_create_two_orders` (asserts already_processed_noop) |
| 14 | Cross-seller access | Yes | `OrdersController::authenticatedSellerId` (derives identity from the bearer token, never a request parameter) | `Case14CrossSellerAccessTest` |
| 15 | Configurable vs. simple SKU | **Explained, not built** | See below | -- |
| 16 | Payment captured, persistence fails | Yes | `OrderRepository::createOrderWithCapture` (capture inside the transaction) + `reconcileOrphanedCapture` (recovery path) | `Case16CapturePersistenceFailureTest` |

## Case 15 -- why explained, not built

The stub's point is specifically about Magento's catalogue structure:
salable quantity lives on the **child simple product**, never the
configurable parent, regardless of which one the buyer clicked through in
the storefront. That's a fact about Magento's MSI/EAV model, not about
this slice's own schema -- `offers_inventory` already keys everything on
`seller_sku` (which the stub confirms is always the child), so the bug
case 15 warns about (accidentally checking or reserving stock against the
parent) **structurally cannot occur in this slice's schema**, because
there is no `available_qty` column anywhere on a "parent" row to begin
with -- only `catalogue_products.parent_sku` exists, as a pure reference
for display/grouping, and nothing in `InventoryRepository` or
`OrderProcessor` ever reads or writes through it.

Building a *meaningful* test for this case would require a real Magento
MSI-backed catalogue (configurable + child simple products, real source
items, real salable-quantity resolution) to actually prove the parent
never gets touched -- a standalone Postgres table with the same column
names would just be testing that my own schema does what I designed it to
do, which isn't the same as proving Magento's MSI behaves correctly when
wired up to it. I'd rather say this plainly than write a hollow green test
against a fixture that can't fail in the way the real system could.

**What I'd actually build, given a Magento environment:** a test that
creates a configurable product with two child SKUs in Magento, sets
salable quantity only on the children via MSI source items, places an
order against one child through the storefront (so the order line carries
the child SKU, exactly as the stub describes), and asserts the *parent's*
stock (which shouldn't exist as a concept at all) is never queried or
decremented anywhere in the integration path -- using Magento's own
`StockItemRepositoryInterface` against both SKUs to prove the parent has
no salable-quantity row to begin with.
