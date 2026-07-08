# Live Test Records

This file tracks deliberate live-system smoke tests so they can be removed later.

## Qoyod

### Contact Cleanup Smoke Test

- Date: 2026-07-08
- Type: Customer/contact
- Name: `Wasif Test - DELETE`
- Qoyod ID: `24`
- Created by command: `php artisan whisper:qoyod-test-contact`
- Scope: Contact only. No invoice, payment, product, bill, or ZATCA-related record was created by this command.
- Cleanup: Qoyod returned HTTP 404 for customer DELETE endpoints, while GET still returned HTTP 200. The contact/customer `Wasif Test - DELETE` with ID `24` was deactivated through the API with `status=Inactive`.

### Dentolize Customer Import

- Date: 2026-07-08
- Type: Dentolize patients imported as Qoyod customer/contact records
- Created by command: `php artisan whisper:import-dentolize-customers --limit=5`
- Scope: Contacts only. No invoice, payment, product, bill, or ZATCA-related record was created by this command.
- Imported IDs:
  - Dentolize `f39ee0df-6433-4e7b-9d2b-f09e206592fc` → Qoyod contact `25`
  - Dentolize `2fac8100-b379-4158-93b5-ada10a3fea6c` → Qoyod contact `26`
  - Dentolize `bd818b88-f25b-40ce-88b2-53d37f666846` → Qoyod contact `27`
  - Dentolize `b974271f-a92f-4bdd-b6bb-a3d35c83f896` → Qoyod contact `28`
  - Dentolize `040b003e-86ed-4571-824c-06c440352e03` → Qoyod contact `29`
