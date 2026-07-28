# ActPrimaryOrderRefs — fill missing primary order references

Shopware 6.7 stores a "primary" transaction and a "primary" delivery directly on the
order row (`order.primary_order_transaction_id` / `order.primary_order_delivery_id`).
Only two code paths ever set them:

- the cart-to-order conversion (`OrderConverter`), and
- the "change payment method" route (`SetPaymentOrderRoute`).

Every other way of creating an order writes through the DAL without touching those
columns. Orders coming from the Admin API, an ERP synchronisation, a marketplace
connector or a custom import therefore keep both references `NULL` — and so does any
order that gains a transaction or a delivery *after* it was created.

The one-time backfill that ships with the 6.7 upgrade cannot help here: it runs once,
at upgrade time, and never sees the orders imported afterwards.

## What breaks while the references are NULL

- **Order list** — the payment status and shipping status columns stay empty.
- **Order detail** — payment and shipping information is missing.
- **Documents** — invoices render without payment method and shipping method, because
  the document criteria resolve `primaryOrderTransaction` and `primaryOrderDelivery`.
- **Mail flow** — order mails miss the same data for the same reason.

## What this plugin does

| Piece | Purpose |
| --- | --- |
| `PrimaryOrderRefsFiller` | The SQL. Batched (1000 orders per pass), DBAL only. |
| `OrderRefsSubscriber` | Listens on `order_transaction.written` / `order_delivery.written` and fills the affected orders immediately. |
| Plugin migration | One-off backfill for orders that already exist at install time. |
| `act:primary-order-refs:fill` | Manual sweep to verify and repair without a new release. |

Selection rules:

- **Primary transaction** — the most recent transaction of the order version
  (`created_at DESC`, `id ASC` as tiebreaker).
- **Primary delivery** — the delivery with the highest shipping costs of the order
  version (`shipping_costs.unitPrice DESC` cast to a decimal, `id ASC` as tiebreaker).

Both rules match how Shopware itself picks these references.

Existing values are **never** overwritten — only `NULL` columns are filled. Deliveries
and transactions are matched inside their own order version; the plugin never links
across version boundaries.

All writes use DBAL, not the DAL. A DAL write would trigger the order indexer and
re-enter the very write stack the subscriber runs in, and it would default the
`primary_order_*_version_id` columns to the live version instead of the version the
referenced row actually carries.

## Requirements

- Shopware 6.7 (`>=6.7 <6.8`)
- PHP 8.4+

## Installation

```bash
bin/console plugin:refresh
bin/console plugin:install --activate ActPrimaryOrderRefs
bin/console cache:clear
```

Installing runs the backfill migration automatically. Nothing else has to be
configured — the plugin has no settings.

## Usage

Check how many orders are affected, without writing:

```bash
bin/console act:primary-order-refs:fill --dry-run
```

Repair them:

```bash
bin/console act:primary-order-refs:fill
```

Restrict the run to one of the two references:

```bash
bin/console act:primary-order-refs:fill --transactions-only
bin/console act:primary-order-refs:fill --deliveries-only
```

## Notes

- If the shop uses Elasticsearch for the admin search, orders repaired by a bulk run
  are refreshed on the next admin index run (`bin/console es:admin:index`); the plugin
  does not dispatch index messages itself.

## License

Proprietary. All rights reserved by Actualize.
