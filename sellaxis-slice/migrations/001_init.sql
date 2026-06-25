-- Forgeline / Sellaxis ingestion slice — schema
-- Postgres. Every table here exists to make one specific failure case safe by
-- construction rather than by convention. See README "Decision Log" for the
-- reasoning behind each one; comments below point at the specific stub case.

CREATE TYPE line_state AS ENUM (
    'pending_acceptance',
    'accepted',
    'refused',
    'shipped',
    'cancelled',
    'refunded'
);

CREATE TYPE inbound_event_status AS ENUM (
    'received',     -- durably stored, not yet processed
    'processed',     -- applied successfully
    'held',          -- valid event, but not appliable yet (out-of-order; case 3)
    'quarantined'    -- malformed / unprocessable; needs human review (case 10)
);

-- ---------------------------------------------------------------------------
-- Sellers (tenants). Needed for case 14 (cross-seller access) and for
-- commission-aware settlement context, even though settlement itself is out
-- of scope for this slice.
-- ---------------------------------------------------------------------------
CREATE TABLE sellers (
    seller_id       TEXT PRIMARY KEY,
    name            TEXT NOT NULL,
    market          TEXT NOT NULL CHECK (market IN ('IN', 'DE')),
    currency        TEXT NOT NULL CHECK (currency IN ('INR', 'EUR')),
    commission_rate NUMERIC(6,4) NOT NULL DEFAULT 0,
    -- a minimal stand-in for seller auth: a portal token maps to exactly one
    -- seller_id. Real auth would be session/JWT-based; this is the smallest
    -- thing that lets case 14 be tested honestly (server derives seller_id
    -- from this token, never from a request parameter).
    portal_token    TEXT UNIQUE NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- Seller -> Magento product mapping (case 5: missing mapping; case 6: SKU
-- unknown system-wide). These are deliberately TWO different tables, not
-- one, because they answer two different questions:
--   - seller_product_map: "does THIS seller's SKU have a mapping at all?"
--   - catalogue_products: "does this Magento SKU exist in the catalogue?"
-- Case 5 is a miss on the first table with a seller_sku that's plausible.
-- Case 6 is a seller_sku for which there will never be a sensible mapping
-- target, because the underlying Magento SKU doesn't exist either.
-- ---------------------------------------------------------------------------
CREATE TABLE catalogue_products (
    magento_sku   TEXT PRIMARY KEY,
    parent_sku    TEXT NULL REFERENCES catalogue_products(magento_sku),
    name          TEXT NOT NULL
);

CREATE TABLE seller_product_map (
    seller_id     TEXT NOT NULL REFERENCES sellers(seller_id),
    seller_sku    TEXT NOT NULL,
    magento_sku   TEXT NOT NULL REFERENCES catalogue_products(magento_sku),
    mapped_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    mapping_source TEXT NOT NULL DEFAULT 'manual',
    PRIMARY KEY (seller_id, seller_sku)
);

-- ---------------------------------------------------------------------------
-- Inventory: one row per Sellaxis offer_id. The `version` column is the
-- entire mechanism for cases 11 (stale update) and 12 (snapshot overlapping
-- incrementals) — both are resolved by the SAME conditional-update rule,
-- regardless of which channel (webhook, poll, nightly snapshot) the update
-- arrived through. See InventoryVersionGuard.
-- ---------------------------------------------------------------------------
CREATE TABLE offers_inventory (
    offer_id       TEXT PRIMARY KEY,
    seller_id      TEXT NOT NULL REFERENCES sellers(seller_id),
    seller_sku     TEXT NOT NULL,
    available_qty  INTEGER NOT NULL,
    version        BIGINT NOT NULL,
    observed_at    TIMESTAMPTZ NOT NULL,
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- Orders & lines. order_state is a DERIVED, read-time projection over line
-- states (computed in OrderRepository), never stored as an independent
-- column — this is the same reasoning as Part A's Decision D3: a multi-
-- seller order's true state lives on its lines/fulfilments, not as one
-- top-level status that can drift from them.
-- ---------------------------------------------------------------------------
CREATE TABLE orders (
    order_ref     TEXT PRIMARY KEY,
    market        TEXT NOT NULL CHECK (market IN ('IN', 'DE')),
    currency      TEXT NOT NULL CHECK (currency IN ('INR', 'EUR')),
    buyer_ref     TEXT NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL,
    -- payment capture bookkeeping for case 16. capture is modelled as
    -- happening at order-creation time (see README on the placement-timing
    -- decision); capture_status separates "gateway has the money" from
    -- "we have durably recorded the order", which is exactly the gap case 16
    -- exploits.
    capture_status TEXT NOT NULL DEFAULT 'none'
        CHECK (capture_status IN ('none', 'captured', 'voided', 'reconciled')),
    capture_amount NUMERIC(14,2) NULL,
    capture_ref    TEXT NULL,
    inserted_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE order_lines (
    order_ref      TEXT NOT NULL REFERENCES orders(order_ref),
    line_ref       TEXT NOT NULL,
    seller_id      TEXT NOT NULL REFERENCES sellers(seller_id),
    seller_sku     TEXT NOT NULL,
    magento_sku    TEXT NULL, -- NULL until/unless seller_product_map resolves it
    qty            INTEGER NOT NULL,
    unit_price     NUMERIC(14,2) NOT NULL,
    currency       TEXT NOT NULL,
    state          line_state NOT NULL DEFAULT 'pending_acceptance',
    -- the out-of-order guard: the occurred_at of the last event that was
    -- actually APPLIED to this line. Used to decide whether a newly-arrived
    -- event is consistent with what's already been applied. See case 3.
    last_event_at  TIMESTAMPTZ NULL,
    refusal_reason TEXT NULL,
    tracking       JSONB NULL,
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (order_ref, line_ref)
);

-- ---------------------------------------------------------------------------
-- Inbound event inbox. This is dedup (case 2, 13) AND the holding area for
-- events that arrived before their logical prerequisite (case 3) AND the
-- quarantine area for malformed payloads (case 10). One table, three jobs,
-- because all three are really the same question: "what do we do with an
-- event we can't apply immediately, and how do we make sure we never lose
-- track of it."
-- ---------------------------------------------------------------------------
CREATE TABLE inbound_events (
    delivery_id   TEXT PRIMARY KEY,        -- unique PER ATTEMPT (case 2's key)
    event_id      TEXT NOT NULL,           -- stable across redeliveries (case 13's key)
    event_type    TEXT NOT NULL,
    occurred_at   TIMESTAMPTZ NULL,        -- null only for quarantined/malformed
    order_ref     TEXT NULL,
    line_ref      TEXT NULL,
    raw_payload   JSONB NOT NULL,
    status        inbound_event_status NOT NULL DEFAULT 'received',
    error         TEXT NULL,
    received_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    processed_at  TIMESTAMPTZ NULL,
    attempts      INTEGER NOT NULL DEFAULT 0
);

-- event_id is NOT unique on its own (a redelivery legitimately reuses it),
-- but we need to find "have we ever successfully processed this logical
-- event" fast — that's a query, not a constraint, because a held event and
-- a processed event can legitimately share an event_id across rows.
CREATE INDEX idx_inbound_events_event_id ON inbound_events(event_id);
CREATE INDEX idx_inbound_events_order_ref ON inbound_events(order_ref) WHERE order_ref IS NOT NULL;
CREATE INDEX idx_inbound_events_status ON inbound_events(status);

-- ---------------------------------------------------------------------------
-- Outbox: every outbound call to the ERP goes through here first. This is
-- the fix for case 4 (ERP commits then times out) and case 16 (payment
-- captured, persistence fails) — both are "we are not sure if the other
-- side already has this" problems, and both are solved the same way: never
-- call out synchronously from the critical path, always go through a
-- durably-recorded, idempotency-keyed row that a separate relay delivers.
-- ---------------------------------------------------------------------------
CREATE TABLE outbox_events (
    id              BIGSERIAL PRIMARY KEY,
    idempotency_key TEXT UNIQUE NOT NULL,
    target          TEXT NOT NULL CHECK (target IN ('erp', 'shipbridge')),
    order_ref       TEXT NOT NULL,
    payload         JSONB NOT NULL,
    status          TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'delivered', 'failed_retryable', 'reconciled')),
    attempts        INTEGER NOT NULL DEFAULT 0,
    last_error      TEXT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    delivered_at    TIMESTAMPTZ NULL
);

CREATE INDEX idx_outbox_status ON outbox_events(status);

-- ---------------------------------------------------------------------------
-- Inventory ledger, append-only, for observability of case 11/12 -- lets the
-- reconciliation API answer "how many stale/superseded updates have we
-- correctly discarded" without that being a guess.
-- ---------------------------------------------------------------------------
CREATE TABLE inventory_update_log (
    id          BIGSERIAL PRIMARY KEY,
    offer_id    TEXT NOT NULL,
    incoming_version BIGINT NOT NULL,
    stored_version_before BIGINT NULL,
    outcome     TEXT NOT NULL CHECK (outcome IN ('applied', 'discarded_stale')),
    source      TEXT NOT NULL CHECK (source IN ('webhook', 'poll', 'snapshot')),
    logged_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
