# Live Test Records

This file tracks deliberate live-system smoke tests so they can be removed later.

## Qoyod

- Date: 2026-07-08
- Type: Customer/contact
- Name: `Wasif Test - DELETE`
- Qoyod ID: `24`
- Created by command: `php artisan whisper:qoyod-test-contact`
- Scope: Contact only. No invoice, payment, product, bill, or ZATCA-related record was created by this command.
- Cleanup: Delete contact/customer `Wasif Test - DELETE` with ID `24` from Qoyod after verification.
