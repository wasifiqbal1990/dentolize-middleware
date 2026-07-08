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
- Cleanup: Qoyod returned HTTP 404 for customer DELETE endpoints, while GET still returned HTTP 200. Contact `24` was scrubbed to `DELETED TEST CONTACT 24` and deactivated through the API with `status=Inactive`.

### Dentolize Customer Import

- Date: 2026-07-08
- Type: Dentolize patients imported as Qoyod customer/contact records
- Created by command: `php artisan whisper:import-dentolize-customers --limit=5`
- Scope: Contacts only. No invoice, payment, product, bill, or ZATCA-related record was created by this command.
- Imported IDs:
  - Dentolize `f39ee0df-6433-4e7b-9d2b-f09e206592fc` → Qoyod contact `25` → deactivated via API
  - Dentolize `2fac8100-b379-4158-93b5-ada10a3fea6c` → Qoyod contact `26` → deactivated via API
  - Dentolize `bd818b88-f25b-40ce-88b2-53d37f666846` → Qoyod contact `27` → deactivated via API
  - Dentolize `b974271f-a92f-4bdd-b6bb-a3d35c83f896` → Qoyod contact `28` → deactivated via API
  - Dentolize `040b003e-86ed-4571-824c-06c440352e03` → Qoyod contact `29` → deactivated via API
- Cleanup: Qoyod contacts `25`-`29` were scrubbed to `DELETED TEST CONTACT {id}` and returned `status=Inactive` after cleanup verification.

### Dentolize Read-Only Patient Flow Probe

- Date: 2026-07-08
- Patient: Dentolize `f39ee0df-6433-4e7b-9d2b-f09e206592fc`
- Scope: Read-only Dentolize API calls only. No Dentolize write operation was performed.
- Findings:
  - `patientDetails(patient: ID)` is available and includes patient, doctor, branch, and timestamp fields.
  - `invoices(..., patient: ID)` is available for patient-linked invoices.
  - `payments(..., patient: ID)` is available with valid fields including `id`, `amount`, `createdAt`, and parent `invoice`.
  - `expenses(...)` is available as a clinic-level resource, but `expenses(..., patient: ID)` is not accepted and `patientExpenses` does not exist.
### Dentolize Patient Flow Import To Qoyod

- Date: 2026-07-08
- Patient: Dentolize `f39ee0df-6433-4e7b-9d2b-f09e206592fc`
- Dentolize write scope: None. All Dentolize operations were read-only API calls.
- Qoyod product created for the test:
  - Product `14`
  - SKU `dentolize-service-wasif-20260708`
  - Purpose: temporary sales service product for invoice line items, because Qoyod product `13` is an expense product and cannot be used on sales invoices.
- Partial/cleanup attempts:
- Final cleanup after client-demo hold request:
  - Receipts `16` and `17` were deleted through `DELETE /receipts/{id}`. These receipts backed the invoice-payment records.
  - Invoice/payment reads for `invoice_payments/16`, `invoice_payments/17`, `invoices/17`, and `invoices/18` returned no records after receipt deletion and invoice deletion.
  - Invoices `17` and `18` were deleted through `DELETE /invoices/{id}` after deleting their backing receipts.
  - Contacts `24`-`35` could not be physically deleted because Qoyod returns HTTP 404 for customer DELETE endpoints; all were scrubbed to `DELETED TEST CONTACT {id}` and verified as `Inactive`.
  - Product `14` could not be physically deleted because Qoyod returns HTTP 404 for `DELETE /products/14`; it was scrubbed to `DELETED TEST Wasif Dentolize Service`, SKU `deleted-dentolize-service-wasif-20260708`, and verified as non-sellable with `is_sold=false` and `pos_product=false`.
- Not created: patient-linked Qoyod expense, bill, or credit note. Dentolize did not expose patient-linked expenses for this patient through the tested API fields.
