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
  - Dentolize `f39ee0df-6433-4e7b-9d2b-f09e206592fc` → Qoyod contact `25` → deactivated via API
  - Dentolize `2fac8100-b379-4158-93b5-ada10a3fea6c` → Qoyod contact `26` → deactivated via API
  - Dentolize `bd818b88-f25b-40ce-88b2-53d37f666846` → Qoyod contact `27` → deactivated via API
  - Dentolize `b974271f-a92f-4bdd-b6bb-a3d35c83f896` → Qoyod contact `28` → deactivated via API
  - Dentolize `040b003e-86ed-4571-824c-06c440352e03` → Qoyod contact `29` → deactivated via API
- Cleanup: Qoyod contacts `25`-`29` returned `status=Inactive` after cleanup verification.

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
  - Contact `30` → deactivated via API after an earlier failed flow run.
  - Contact `31` → deactivated via API after invoice creation failed with expense product `13`.
  - Contact `32` → deactivated via API; draft invoice `16` was deleted via API after payment was rejected for a draft invoice.
  - Contact `33` → deactivated via API after invoice `17` and payment `16` were created with the wrong VAT fallback. Qoyod did not expose a working delete endpoint for invoice payment `16`, and deleting invoice `17` was rejected after payment/accounting state existed.
  - Contact `34` → deactivated via API after the corrected rerun hit Qoyod's duplicate invoice reference constraint.
- Final corrected sample flow retained in Qoyod:
  - Contact `35` → deactivated via API on 2026-07-08 after the client-demo hold request.
  - Invoice `18`
  - Invoice reference `DENTO-INV-c19b788a-cf44-4f52-8c22-f13d3e43f741-corrected-0tax`
  - Invoice status verified by API: `Paid`
  - Invoice total verified by API: `299.0`
  - Paid amount verified by API: `299.0`
  - Payment `17`
  - Payment reference `DENTO-PAY-bc33e980-1065-4f75-981b-7e83f619afd1-corrected-0tax`
- Cleanup after client-demo hold request:
  - Payment `17`: Qoyod returned `404` for `DELETE /invoice_payments/17`; no delete endpoint is exposed in the API collection.
  - Invoice `18`: Qoyod rejected `DELETE /invoices/18` with `external_line_items` validation after payment/accounting state existed.
  - Contact `35`: deactivated through the Qoyod API and verified as `Inactive`.
  - Product `14`: Qoyod returned `404` for `DELETE /products/14`; product was renamed to `DELETED TEST Wasif Dentolize Service` and SKU `deleted-dentolize-service-wasif-20260708`.
- Not created: patient-linked Qoyod expense, bill, or credit note. Dentolize did not expose patient-linked expenses for this patient through the tested API fields.
