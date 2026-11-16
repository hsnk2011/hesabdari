# hesabdari — notes for coding sessions

## Terminology (read before touching inventory or products)

- «شناسه انبار», «شناسه محصول», «شناسه محصولات» and «شناسه کالا» all mean the same thing:
  `inventory_items.serial_id`, the unique ID of each physical item (each rug) that enters and leaves
  the system, e.g. a real tag `1000033` or an auto code `INV-001266`.
- They do NOT mean `products.id` (a product is a design/model with many items; its id is internal and
  not shown in the UI) and NOT `warehouses.id` (a storage location).
- Full glossary: «واژه‌نامه شناسه‌ها» in DOCUMENTATION.md.
