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

## Requirements

- Shopware `>=6.7 <6.8`, tested with 6.7.16.0
- PHP 8.4+

## What breaks while the references are NULL

- **Order list** — the payment status and shipping status columns stay empty.
- **Order detail** — payment and shipping information is missing.
- **Documents** — invoices render without payment method and shipping method, because
  the document criteria resolve `primaryOrderTransaction` and `primaryOrderDelivery`.
- **Mail flow** — order mails miss the same data for the same reason.

## What this plugin does

| Piece | Purpose |
| --- | --- |
| `PrimaryOrderRefsFiller` | The SQL. Batched (1000 ids per statement), DBAL only. |
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

Every id list is split into batches of 1000 before it reaches a statement — both the
orders found by a sweep and the ids reported by a single write. A bulk import writing
tens of thousands of rows in one request would otherwise produce one statement with a
placeholder per id, beyond what the MySQL protocol accepts.

## Failure behaviour

The subscriber never lets a database error escape. `order_transaction.written` and
`order_delivery.written` are dispatched *after* the write transaction has committed, so
an exception escaping the subscriber would fail a checkout or import request for an
order that already exists — and the caller would likely retry it. Instead the failure is
logged at `error` level:

```
Failed to fill primary order transaction references, 12 written row(s) left unresolved
```

The affected orders keep their `NULL` references until the next run of
`act:primary-order-refs:fill`. Order ids are not logged — they are raw binary and
identify customer orders. Treat such an entry as a prompt to run the command.

## Installation

### Via Composer (recommended)

```bash
composer require actualizer/primary-order-refs
bin/console plugin:refresh
bin/console plugin:install --activate ActPrimaryOrderRefs
bin/console cache:clear
```

### Manual Installation

1. Copy the plugin folder to `custom/plugins/ActPrimaryOrderRefs`
2. Run the following commands from your Shopware root directory:
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

## Support

For issues and feature requests, please use the GitHub issue tracker.

## License

This plugin is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Credits

Developed by Actualize

---

Made with ❤️ for the Shopware Community
