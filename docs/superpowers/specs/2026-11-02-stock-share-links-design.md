# Stock share links for colleagues (لینک موجودی برای همکار) — design

Date: 2026-11-02 · Version: 2.7.0

## Why

Colleagues and resellers (همکار و فروشنده) cannot sell the shop's rugs because
they don't know what is in stock. They need one link, opened from WhatsApp on a
phone, that always shows the current stock with photos, sizes and prices.

## Decided with the user

- Audience: colleagues and resellers, both in-house and outside.
- Price per link: sales price, colleague price, or no price.
- Colleague price: both ways. A collection (or single product) can have its own
  «قیمت همکاری هر متر». Without one, the sales price minus the link's
  percentage is used.
- A link never expires. It stays valid until it is revoked.
- Page layout: the three mockups combined. Card view (default) and list view
  toggle. Each size row shows count and price. Tapping the count opens the
  serial IDs (شناسه انبار). Tapping a photo opens all photos of that product.
- Each link can be limited by warehouse, collection / single products, and size.

## Builder's calls (not asked)

- The link is live: every visit reads the stock of that moment.
- Stock means in_stock + returned pieces. Not counted: virtual pieces, pieces in
  `order` warehouses, inactive products.
- The public list never carries cost price, piece notes, supplier or warehouse
  names. Price mode, percentage and scope are stored server-side (not in the URL).
- When counts or serials are hidden, they are not sent to the browser at all.
- No price → «تماس بگیرید». Products without photos get a short placeholder.
- Views are counted (last view + count) so the owner sees whether a link is used.
- Managing links needs `manage_collections` (the same permission as editing prices).
- Colleague prices show on the price list only for users with that permission,
  and never in the printed price list.
- The last percentage used pre-fills the next link (setting `stock_share_partner_pct`).
- Editing a link keeps its token, so the shared URL keeps working.

## Shape

- `stock_share_links` table; `product_collections.partner_price_per_meter`,
  `products.partner_price` (NULL = use the link percentage).
- `api/models/StockShareLink.php` (pricing rule `piecePrice`, scope, public list),
  `api/controllers/StockShareController.php` (`get_/save_/revoke_stock_share_link`).
- Public page `stock.php?c=TOKEN` (standalone, no login, noindex, no-store).
- `js/stock-share-manager.js` + modal `#stockShareModal` in both template sets.
- Tests: `tests/stock_share_link_test.php`.
