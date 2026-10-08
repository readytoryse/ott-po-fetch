# OTT PO Fetch

Syncs outstanding purchase orders from OrderWise to Shopify. For every product variant with stock on order, it writes the incoming PO quantities and supplier promised dates into a variant metafield on the `ott-trade-portal` Shopify store.

It is a command-line Laravel app. There is no UI and no API; everything runs from one scheduled Artisan command.

**Status (8 October 2026):** working end to end on a local machine and tested against the live OrderWise export and the live Shopify store. Not hosted yet. The open items for hosting are in [Action items before go-live](#action-items-before-go-live).

## Action items before go-live

| # | Item | Why |
|---|---|---|
| 1 | **Host the app** on a server with PHP 8.3+, MySQL and cron. See [Hosting](#hosting). | Currently runs only on a developer machine. |
| 2 | **Add the cron entry** for the Laravel scheduler. | The 3-hour schedule does nothing without it. |
| 3 | **Set `APP_TIMEZONE`** explicitly (it is empty). | "Today" decides which POs are fetched and when a promised date counts as passed. With it empty the app uses the server's PHP timezone, which was UTC locally. |
| 4 | **Set `APP_ENV=production` and `APP_DEBUG=false`.** | Standard production settings. |
| 5 | **Rotate the Shopify client secret**, then put the new one in the server `.env`. | The secret and a 24-hour access token appeared in screenshots during development. |
| 6 | **Remove `SHOPIFY_ADMIN_TOKEN` from `.env`** if it is still there. | No code reads it. The app generates its own token. |
| 7 | **Decide how OrderWise codes map to Shopify SKUs.** See [Known issue: SKU mismatch](#known-issue-sku-mismatch). | Only 7 of 559 SKUs currently match. This is the main thing limiting real-world value. |
| 8 | **Confirm the metafield definition** `custom.incoming_purchase_orders` (type JSON, on variants) exists in Shopify admin and is exposed to the storefront/theme as needed. | The app writes the value; it does not create the definition or any theme code. |
| 9 | **Commit and push the project** to `origin` and keep `.env` out of it. | The repository and remote exist, but nothing is committed yet. |
| 10 | **Decide who checks for errors.** | The app sends no email. Errors are only in the PO sync log and the `po_sync_issues` table. |

## How it works

Every run of `php artisan orderwise:sync-po` does this:

1. **Fetch from OrderWise.** Gets a token (`GET /token/gettoken`, basic auth, cached 55 minutes) and calls export definition 49 (`POST /system/export-definition/49`) with one parameter: `@since` = today's date.
2. **Filter.** Drops lines with no outstanding quantity and lines whose supplier promised date is before today.
3. **Store.** Compares the result with `po_lines` by OrderWise PO line id and records each line as new, updated, removed or unchanged.
4. **Push to Shopify** (only when `PO_SYNC_PUSH_SHOPIFY=true`). For each SKU it builds the full list of incoming POs, finds the variant by SKU, reads the current metafield value, and replaces it.
5. **Report.** Writes the run to the PO sync log. Every problem is written to the PO sync log and stored in `po_sync_issues`. No email is sent.

### The OrderWise export

Export definition 49 is maintained in OrderWise, not in this repository. The app depends on it accepting `@since` and filtering on the **promised date**:

```sql
SELECT
    poh.poh_order_number,
    poh.poh_datetime           AS po_placed_datetime,
    sd.sd_name                 AS supplier,
    pol.pol_id                 AS po_line_id,
    vad.vad_variant_code       AS sku,
    pol.pol_vad_description    AS description,
    pol.pol_qty_ordered        AS qty_ordered,
    ISNULL(recv.qty_received, 0) AS qty_received,
    pol.pol_qty_ordered - ISNULL(recv.qty_received, 0) AS qty_outstanding,
    pol.pol_date_promised      AS supplier_promised_date,
    pol.pol_date_required      AS date_required
FROM purchase_order_line pol
JOIN purchase_order_header poh ON poh.poh_id = pol.pol_poh_id
JOIN supplier_detail      sd  ON sd.sd_id   = poh.poh_sd_id
JOIN variant_detail       vad ON vad.vad_id = pol.pol_vad_id
OUTER APPLY (
    SELECT SUM(polr.polr_qty_received) AS qty_received
    FROM purchase_order_line_received polr
    WHERE polr.polr_pol_id = pol.pol_id
) recv
WHERE pol.pol_date_promised >= @since
ORDER BY poh.poh_datetime DESC, poh.poh_order_number, pol.pol_id;
```

The `WHERE` line above is what the export is understood to use now; the rest is the query as last shared. Check the live definition in OrderWise if behaviour looks wrong. If the export's parameters or column names change, the sync fails with a logged error and a `po_sync_issues` row (this happened once during development when `@date_from`/`@date_to` were replaced by `@since`).

### What is written to Shopify

- **Metafield:** `custom.incoming_purchase_orders`, type `json`, on the product variant.
- **Value:** a list with one entry per PO and promised date, sorted by date then PO number.

```json
[
  {"qty": 11, "date": "2026-10-15", "reference": "PO70809"},
  {"qty": 12, "date": "2026-10-28", "reference": "PO70766"}
]
```

| Field | Source |
|---|---|
| `date` | Supplier promised date (`pol_date_promised`), date only |
| `qty` | Outstanding quantity (ordered minus received), summed when one PO has several lines for the SKU on the same date |
| `reference` | PO number |

- The value is always **replaced in full**, never merged.
- When a SKU has no future POs left, the metafield is set to `[]`. It is not deleted.
- If the value has not changed since the last successful push, Shopify is not called for that SKU.

### Shopify authentication

The app uses the client credentials grant: it posts `SHOPIFY_CLIENT_ID` and `SHOPIFY_CLIENT_SECRET` to `https://{store}/admin/oauth/access_token` and caches the returned token until one minute before it expires (about 24 hours). A 401 clears the cache and retries once. The Shopify app needs the `write_products` scope.

## Commands

| Command | What it does |
|---|---|
| `php artisan orderwise:sync-po` | Runs one full sync. Pushes to Shopify only when `PO_SYNC_PUSH_SHOPIFY=true`. |
| `php artisan schedule:list` | Shows the schedule and the next run time. |
| `php artisan test` | Runs the test suite (30 tests). All HTTP calls are faked; nothing external is touched. |

To run a sync without touching Shopify, set `PO_SYNC_PUSH_SHOPIFY=false` for that run.

## Schedule

Defined in `routes/console.php`:

```php
Schedule::command('orderwise:sync-po')->everyThreeHours()->withoutOverlapping(60);
```

It runs at 00:00, 03:00, 06:00 and so on in the app timezone. A run will not start while the previous one is still running (the lock expires after 60 minutes). A run currently takes well under a minute.

## Hosting

**Requirements:** PHP 8.3 or newer, Composer, MySQL, and cron. No queue worker, no Node build and no public web access are needed for the sync.

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env        # then fill in the values below
php artisan key:generate
php artisan migrate --force
php artisan config:cache
```

Cron entry (runs the Laravel scheduler every minute):

```
* * * * * cd /path/to/ott-po-fetch && php artisan schedule:run >> /dev/null 2>&1
```

After deploying, run `php artisan orderwise:sync-po` once by hand and check the output and the log. The first run on an empty database pushes every matching SKU.

Notes:

- `CACHE_STORE=database` is required as configured: the OrderWise and Shopify tokens and the schedule lock live in the cache table.
- After changing `.env` on a server that uses `config:cache`, run `php artisan config:cache` again.
- `storage/` and `bootstrap/cache/` must be writable by the user that runs cron.
- On the development machine (Windows, WAMP) the default `php` is 8.1, which fails. Use `C:\wamp64\bin\php\php8.3.28\php.exe`.

### Environment variables

| Variable | Purpose | Notes |
|---|---|---|
| `APP_TIMEZONE` | Timezone for "today" and the schedule | **Empty now. Must be set.** |
| `ORDERWISE_BASE_URL` | OrderWise API base URL | `https://sdmg.orderwisecloud.com/owapi` |
| `ORDERWISE_USERNAME`, `ORDERWISE_PASSWORD` | OrderWise API login | Secret |
| `ORDERWISE_EXPORT_ID` | Export definition id | `49` |
| `ORDERWISE_TOKEN_CACHE_MINUTES` | How long the OrderWise token is cached | `55` |
| `ORDERWISE_TIMEOUT_SECONDS` | HTTP timeout for OrderWise | `30` |
| `SHOPIFY_STORE_DOMAIN` | Store domain | `ott-trade-portal.myshopify.com` |
| `SHOPIFY_CLIENT_ID`, `SHOPIFY_CLIENT_SECRET` | Shopify app credentials | Secret. Rotate before go-live. |
| `SHOPIFY_API_VERSION` | Admin API version | `2026-10` |
| `PO_SYNC_PUSH_SHOPIFY` | `true` writes metafields, `false` only updates the local database | `false` in `.env.example` |
| `PO_SYNC_LOG_DAYS` | Days of PO sync logs to keep | `14` |
| `DB_*` | MySQL connection | Local database is `ott_po_fetch` |

## Database tables

All tables are created by the migrations in `database/migrations`.

| Table | One row per | Purpose |
|---|---|---|
| `po_sync_runs` | Sync run | Start and finish time, status (`running`, `success`, `failed`), row counts, error text |
| `po_lines` | OrderWise PO line (`pol_id`) | Current state of each line. `is_active` is false once the line is received, removed or past its promised date |
| `po_line_changes` | Change to a PO line | `new`, `updated` or `removed`, with old and new values as JSON |
| `variant_metafield_syncs` | SKU | Current push state: matched variant id, last payload, status (`synced`, `unmatched`, `ambiguous`, `error`, `pending`) |
| `variant_metafield_updates` | Successful metafield write | **Audit history.** `old_value` (read from Shopify just before the write), `new_value`, `metafield_updated_at`, `run_id` |
| `po_sync_issues` | Problem | **Issue log.** `stage`, `severity`, `sku`, `variant_gid`, `message`, `details` (JSON), `run_id` |

`po_sync_issues.stage` is one of `orderwise_export`, `shopify_variant_lookup`, `shopify_metafield_update`, `shopify_sync`.

`po_sync_issues.severity`:

- `error`: the OrderWise fetch failed, or a Shopify lookup or metafield write failed.
- `warning`: a SKU has no matching Shopify variant (or more than one). Recorded once when the SKU first becomes unmatched, not on every run.

Every row is also written to the PO sync log as a `PO sync issue.` line at the same level, with the run id, stage, SKU, variant id, message and details.

Nothing prunes `po_line_changes`, `variant_metafield_updates` or `po_sync_issues`. They grow slowly, but add a cleanup job if long-term size matters.

## Error handling and monitoring

- **No email.** The app does not send mail. Check the log and the `po_sync_issues` table.
- **PO sync log.** `storage/logs/po-sync-YYYY-MM-DD.log`, kept for 14 days. One summary line per run, the unmatched and failed SKU lists, one `PO sync issue.` line per error or warning, and one line per outstanding PO line.
- **Issue table.** `po_sync_issues` holds the same errors and warnings as the log, and is not pruned.
- **Application log.** `storage/logs/laravel.log`, for anything the framework itself reports.
- **Exit code.** The command exits non-zero when OrderWise fails or any SKU fails to push.
- **Retries.** Shopify 429 and throttled responses are retried with back-off. Unmatched and failed SKUs are retried on every run.

Useful checks:

```sql
SELECT id, status, started_at, rows_fetched, error FROM po_sync_runs ORDER BY id DESC LIMIT 10;
SELECT status, COUNT(*) FROM variant_metafield_syncs GROUP BY status;
SELECT * FROM po_sync_issues WHERE severity = 'error' ORDER BY id DESC LIMIT 50;
SELECT sku, old_value, new_value, metafield_updated_at FROM variant_metafield_updates ORDER BY id DESC LIMIT 20;
```

## Known issue: SKU mismatch

The sync matches OrderWise `vad_variant_code` to the Shopify variant SKU exactly. Most do not match.

- The store has 211 products and 2,130 variants.
- As of the last run, 559 SKUs are tracked: **7 matched, 552 unmatched**.
- Shopify uses a different naming scheme for many products. For example Fujikura variants are `Fuj-Spe-NX-Gold-50-R` in Shopify, while OrderWise sends codes such as `VP255R` and `2VNTBL70WS`.
- A few OrderWise codes contain stray spaces (`VP255R ` with a trailing space, `TGI-50G -DEMO`, `GRAPHITE - 75G-S`), which may prevent a match even after the SKU is corrected in Shopify.

The 7 that match were matched by hand for testing: `SETFUJIAS`, `HC8303M`, `2VNTBL70WS`, `VP265R` (with PO data) and `TG1212`, `TG1314`, `TG1710` (now `[]`).

Options, to be decided:

1. Change the Shopify variant SKUs to the OrderWise codes. No code change; unmatched SKUs are retried every run and will sync on their own.
2. Store the OrderWise code somewhere else in Shopify (barcode or a metafield) and change the lookup in `ShopifyClient::findVariantsBySku()`.
3. If these products belong in a different Shopify store, point the credentials at that store.

## Code map

| File | Responsibility |
|---|---|
| `app/Console/Commands/SyncPurchaseOrders.php` | The sync command: orchestrates the run, logging and issue recording |
| `app/Services/OrderWiseClient.php` | OrderWise token and export call |
| `app/Services/PurchaseOrderSyncService.php` | Filters rows and applies them to `po_lines` and `po_line_changes` |
| `app/Services/ShopifyClient.php` | Shopify token, GraphQL calls, throttling and retries |
| `app/Services/ShopifyMetafieldSyncService.php` | Builds the metafield payloads, matches SKUs, writes metafields, records update history |
| `app/Services/SyncIssueRecorder.php` | Writes each issue to the PO sync log and to `po_sync_issues` |
| `routes/console.php` | The 3-hour schedule |
| `config/services.php` | Maps the environment variables above to config |
| `tests/Feature` | Tests for each of the above |

## Behaviour worth knowing

- **Only future POs.** A line disappears from the metafield the day after its promised date, even if the stock has not arrived. Late POs are therefore not shown.
- **POs with no promised date** are stored but never pushed.
- **Every run re-checks every tracked SKU**, not only the ones that changed in that run. This is deliberate: it guarantees a stale value is corrected even if an earlier run was interrupted or ran with the push switched off.
- **Old values come from Shopify**, not from the local database, so a manual edit in Shopify admin shows up in `variant_metafield_updates.old_value` at the next change.
