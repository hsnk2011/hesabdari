# دارایی‌ها (Assets) — design

Date: 2026-10-12. Status: section 1 (UI) approved by the user; remaining decisions taken by default (user said «انجام بده»), listed under *Defaults chosen*.

## Goal
Hold non-carpet holdings — currency, coins, gold, furniture, equipment, anything — outside inventory/products,
with invoice-style payments (cash, new check, endorse received check, partner personal account, credit), realized
gain/loss on sale, and a live value for currency/gold from tgju.org.

## Out of scope
Depreciation (user chose manual value), multi-currency accounts, asset documents in sales/purchase reports.

## Data
- `assets`: id, entity_id, name, category (free text with suggestions: ارز، طلا و سکه، اثاثیه، تجهیزات، خودرو، سایر),
  unit, price_key (tgju key, nullable), manual_value + manual_value_date (total value, used when no price_key),
  notes, is_archived, created_at.
- `asset_documents`: id, entity_id, doc_type enum(purchase, sale), doc_number, date, person_id, person_type
  (supplier|customer|contact, nullable), description, total_amount, paidAmount, created_at.
- `asset_document_items`: id, document_id, asset_id, quantity decimal(18,4), unit_price, amount,
  cost_amount (sale rows: moving-average cost at that point), gain_amount (sale rows: amount − cost).
- `asset_prices`: price_key PK, title, price (toman), source, fetched_at.
- `payments.invoiceType` / `checks.invoiceType` enums gain `asset_purchase`, `asset_sale` (invoiceId = asset_documents.id).

"Disposal" = sale document with amount 0 (loss = remaining cost).
«موجودی اول دوره» (`asset_documents.is_opening`): purchase document for assets owned before using the app — no counterparty, no payment, no money movement.

## Behaviour
- Holdings per asset: Σ purchase qty − Σ sale qty. Cost: moving average recomputed chronologically
  (date, id) after every document save/delete; sale rows store cost_amount and gain_amount.
- A sale cannot exceed the quantity held at its date.
- Payments go through `Invoice::syncInvoicePayments` (made public) with invoiceType asset_purchase/asset_sale;
  `Payment` / `Check` map these types to `asset_documents` (paidAmount, delta sign like purchase/sales).
- Deleting a document deletes its open payments (same rules as invoices: processed checks block deletion).
- Period lock (`assert_transaction_date_editable`) applies.

## Effects elsewhere
- Not in: products, inventory, sales/purchase lists, sales/purchase reports, analytics.
- Person balances (customer/supplier/contact lists, net balance, dashboard receivables/payables,
  receivable/debt reports, closing snapshot) include asset documents with that person via one helper
  `AssetLedger::personNet()` — positive = the person owes us.
- Person statement shows asset documents and their payments.
- Profit & loss: `totalAssetGain` (Σ gain_amount of sale rows in the period) is added to net operating profit,
  shown as its own row «سود/زیان فروش دارایی».
- Account closing: assets at cost (book value) are added to total assets.
- Account ledger / transactions list label asset payments «سند خرید/فروش دارایی #n».

## Prices (tgju.org, unofficial)
`AssetPriceService` fetches https://www.tgju.org/currency, /coin, /gold-chart and reads rows
`data-market-row="<key>"` (price in rial → divided by `currency_rial_divisor`). Keys offered:
price_dollar_rl, price_eur, price_aed, price_gbp, sekee, sekeb, nim, rob, gerami, geram18, geram24, mesghal.
Scheduler task every hour + manual refresh button. On failure the last price stays with its timestamp.

## Migration of existing data
Tool «تبدیل فاکتور خرید به سند دارایی»: picks a purchase invoice; creates an asset purchase document
(same date, supplier, rows by product name/qty/price, payments re-pointed to the new document),
removes the invoice's inventory rows and the invoice, and deletes the product when nothing else uses it.

## Permission
`manage_assets` («مدیریت دارایی‌ها»), granted on upgrade to roles that have `manage_accounts`.

## Defaults chosen (user may revise)
1. Closing/balance uses cost, not market value (market shown for information).
2. Counterparty types: supplier, customer, contact (partner payments go through the partner's personal account).
3. Realized gain/loss enters net profit, separate row.
4. Gain uses moving average cost.

## Testing
`tests/assets_test.php`: purchase with cash + check, sale with gain, oversell rejected, cost recompute after
deleting a purchase, person balance includes credit document, P&L gain, price parser on a saved HTML sample,
invoice → asset conversion.
