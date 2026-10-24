# Inventory dashboard — 24 October 2026

- Inventory models are read directly from Machines. New catalog models appear automatically for full-access users; no manual Product-to-Machine linking interface remains.
- The list shows model name, available quantity, Edit and Sell actions. Showroom names are inside the editor only.
- The editor uses native dashboard accordion sections per permitted showroom, with chassis, engine, color and optional images. Saving does not delete omitted pieces; stale/sold piece updates are rejected.
- Selling uses a three-step wizard: piece selection, customer details, payment amounts. Confirmation creates a Letter and marks the piece sold without deleting its record. Concurrent duplicate sales are prevented by a transaction and row lock.
- Employees without Inventory full access see only available models and quantities in their assigned `staff.warehouse_id`. Other showrooms are excluded from the editor, sale selectors, counts and server-side save/sale queries. Unassigned employees see no stock. Owners and users with Inventory `all` permission retain access to every showroom.
- Model names shown in Letters prefer the canonical Machine name.
- The migration only adds nullable indexed `inventory_items.sold_at`; existing records, storage, environment and server routing are preserved.
- Legacy Product rows without a machine_id remain intact. Their physical pieces are excluded from catalog-model counts and a scoped notice reports them. No model assignment was guessed and no legacy data was deleted.

Validation: isolated MySQL feature tests cover automatic catalog listing, accordion edits, sale-to-Letter workflow, duplicate sales, stale edits, payment validation, role access and showroom isolation including forged showroom/item IDs.