# NileBit WhatsApp POS gateway

Local implementation in progress. This is one WhatsApp identity with switchable Retail and Production business connections. The POS APIs remain the source of truth for stock, sales, costs and customer debt. No second financial ledger is maintained here.

## Implemented

- Signed Meta webhook verification, configured phone-number filtering, encrypted inbox/outbox and queued processing.
- Expiring web account-linking flow. Passwords go to the selected existing POS API, never WhatsApp. Business access is checked against that account's current memberships.
- Encrypted POS tokens, multiple businesses, paginated business switching and connecting additional accounts.
- Read-only customer/debtor browsing, current balance lookup, pagination and `debt Sarah` search.
- Paginated stock browsing/search and configured-unit details, guided product selection/search, configured selling units, quantities, up to 200 cart lines with paginated review, split/partial payment recording, discounts, cart edits/removal, due dates, existing customer selection/search, walk-ins for paid sales, named customers for credit.
- Explicit sale review and confirmation. A persisted immutable confirmation payload and stable POS idempotency key survive an ambiguous network timeout. POS stock checks and expected-total price guard remain authoritative.
- At most ten list rows and Meta text/title limits in sale menus. Superseded, expired and foreign-sender actions cannot submit a sale.
- Recovery command for persisted unscheduled inbox/outbox records.

- Customer creation/repayments, supplier creation/repayments, products, purchases, stock counts/opening/damage/loss, expenses, shifts, production recipes and batch plan/complete/cancel with explicit review.
- Shared POS operation ledger/outcome lookup and Recent tasks reconciliation/resume; production revision guards prevent stale batch mutations.
- Scoped expiring POS bot tokens, disconnect in chat and revocation controls in web Settings.
- Role-aware dashboard/sales/debt/profit summaries and paginated fresh POS sales receipts.
- Delayed older inbound messages cannot change newer chat tasks; starting a different write task requires finishing/cancelling the active one.

Not live on WhatsApp. No Meta credentials are configured. Refunds/voids, expanded production/delivery flows, new-business onboarding, unrestricted natural-language questions and subscriptions remain unfinished. See the saved audit backlog for the complete product scope.

## Local setup

Use PHP 8.5 and the Composer lockfile. Install dependencies with `composer install`, copy `.env.example` to `.env`, then run `php artisan key:generate`. Configure a dedicated gateway database. This database holds conversations and encrypted access tokens, not POS inventory records.

Set `RETAIL_API_URL` and `PRODUCTION_API_URL` to their API roots (including `/api`). Production requires HTTPS. Only localhost HTTP API roots are accepted in local/testing environments.

Run `php artisan migrate`, `php artisan serve` and a separate `php artisan queue:work --tries=5`. Keep `WHATSAPP_ENABLED=false` until the webhook, credentials and worker are ready. Preserve APP_KEY across restarts and deployments; changing it makes stored credentials and messages unreadable.

Apply all accompanying POS backend changes before enabling bot writes: payment and generic operation replay migrations, bot capability/outcome routes, scoped token access, six-row API pagination, expected_total guards and the Production batch revision migration. Otherwise a changed price could bypass the chat review safeguard.

## Meta onboarding

1. Create a NileBit Labs business portfolio in Meta Business Suite.
2. Register a Meta developer app, select the WhatsApp use case and associate the portfolio.
3. Open WhatsApp API Setup/Getting Started and begin with the Meta test number. Verify an allowed personal test recipient and send Meta's sample message.
4. Note Phone Number ID and WhatsApp Business Account ID. Supply access tokens and app secret through private environment configuration, never source control or chat.
5. Obtain a public HTTPS gateway URL. Configure GET/POST `/api/whatsapp/webhook` as the callback, using the same private verification token as `WHATSAPP_VERIFY_TOKEN`, and subscribe to messages for the configured account/number.
6. Set `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_ACCESS_TOKEN` and the API version currently supported by the Meta app. No production version is guessed by this project.
7. Replace temporary credentials with a suitably scoped system-user token for the live deployment. Complete Meta's applicable account/number verification and billing requirements before public rollout.

Use a test number before migrating an existing WhatsApp account. Registration/coexistence choices must be checked for the actual number and onboarding route; do not delete an existing account just to experiment.

Official starting reference: https://www.postman.com/meta/whatsapp-business-platform/collection/wlk6lh4/whatsapp-cloud-api

## Queues and recovery

Run the queue worker continuously under a process supervisor. Execute `php artisan whatsapp:recover` periodically or after a queue outage. It re-enqueues unprocessed inbox records and unsent outbox records. Monitor failed jobs and queue latency.

Outbound delivery is at least once. `sent_at` means Meta accepted the request and returned an ID; it does not prove delivery or reading. Ambiguous network failure after Meta acceptance can result in a duplicate chat message. Financial duplicate prevention is separately enforced using the stable operation key in the POS. Free-form messages outside the 24-hour customer window are abandoned, not sent; template-based re-engagement is not implemented.

## Testing

`php artisan test --compact` runs isolated tests with fake HTTP and a fresh test database. No Meta messages are sent.

Three opt-in integration tests use only the known disposable audit POS servers on ports 8250/8251. They record one synthetic sale each and verify the receipt and stock through the same POS APIs. Run `NILEBIT_LOCAL_INTEGRATION=1 php artisan test --compact tests/Feature/LocalPosIntegrationTest.php` only with those audit fixtures running, never with real customer accounts or forwarded production services.

## Remaining release requirements

- Production linking now requires HTTPS and the configured APP_URL host, encrypted sessions and secure cookies by default. Set TRUSTED_PROXIES only to known proxy IPs/CIDRs; arbitrary forwarded headers are not trusted. Keep gateway access logs from recording the /link/ bearer token path. Secret rotation, retention/pruning, monitoring and backup procedures remain release work.
- PostgreSQL concurrent webhook/confirmation tests, queue ordering/recovery tests under multiple workers and a real Meta test-number round trip.
- Full catalogue paging at the POS API layer and customer paging beyond 500 search matches; chat product menus themselves page through the loaded catalogue. Search narrows customer matches.
- Remaining Retail/Production modules from the audited backlog. Backend payment methods record how a sale was paid; they do not collect mobile money or card funds.


## Hosting checkpoint

The checked-in `render.yaml` currently describes the owner-requested **free initial test**: one web container supervising Apache and the queue worker, plus Render free Postgres. This is not an always-on SLA, and the free database expires after 30 days. Uptime checks do not remove those platform limits. Move DB_URL to durable external Postgres or upgrade storage before expiry; export and verify a backup first. No paid plan is authorized.

The container omits URL paths/referrers from Apache access logs to avoid recording bearer linking URLs. Render terminates public HTTPS; Apache marks the internally forwarded request as HTTPS without trusting client forwarding headers. Keep the same APP_KEY_BASE64 throughout redeployments. Secrets are entered privately in Render; no Meta secrets are committed.


The combined free mode uses `whatsapp:work-on-demand`: its supervisor waits on a private Unix socket when no work is queued. Incoming signed webhooks persist work before sending the wake signal. Delayed retries use the next database-job timestamp, and a restart requeues unprocessed inbox/outbox records. This avoids constant idle SQL polling that would keep a serverless Postgres compute awake. A separate continuously running worker mode remains available if the hosting plan later changes. A health-check URL must remain database-free.
