# Braj Animal Care — donor dashboard

Laravel 13 / PHP 8.3. Donors sign in, give once or on a recurring plan through Razorpay, Stripe or
PayPal, and download an 80G PDF receipt for every payment including automatic renewals. Admins see
everything and manage plans.

```bash
composer install
cp .env.example .env && php artisan key:generate     # already done if you cloned this directory
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

Seeded logins — **change these before deploying**:

| Role | Email | Password |
| --- | --- | --- |
| Admin | `admin@brajanimalcare.com` | `password` |
| Donor | `donor@example.com` | `password` |

## Roles

**Admins cannot donate.** `/dashboard`, `/give`, `/subscriptions`, `/transactions` and `/profile`
redirect an admin to `/admin`, and their navigation shows only the admin pages. An admin giving
through the account they administer just adds noise to the very tables they are meant to be reading —
staff who want to give use a donor account.

Admins get: **Overview** · **Transactions** (all, paginated, filterable) · **Donors** (with a page per
donor showing their subscriptions and payments) · **Subscriptions** · **Plans**.

`is_admin` is **not mass-assignable**, so no stray `create($request->all())` can grant it — which also
means `$user->update(['is_admin' => true])` silently does nothing. Use:

```bash
php artisan user:admin someone@example.com            # grant
php artisan user:admin someone@example.com --revoke   # revoke
```

## Guest donations

Nobody has to register to give. `/give` is open, the same details form is used, and the account is
created **after** the payment clears — an account made from an abandoned checkout is litter, and a
set-password email to someone who never gave is worse.

`LinkOrCreateDonorAccount` runs on the queue once payment is confirmed:

| Case | What happens |
| --- | --- |
| Email already has an account | The donation is attached to it. **No email is sent** — an unrequested reset would also confirm to whoever typed the address that the account exists. |
| Email is new | Account created with an unguessable random password that is never sent anywhere, then a **set-password link** (a normal password-reset token). |

Two things it deliberately does *not* do:

- **It never signs the guest in.** Paying proves control of a card, not of a mailbox. Access to a
  donation history is granted only by following the emailed token.
- **It never overwrites an existing profile.** A guest form is untrusted input; only blank fields are
  backfilled, so a stranger typing your email cannot rewrite your name or phone.

Earlier unlinked donations from the same address are attached at the same time, so a repeat guest
donor sees their whole history the first time they sign in.

### How a guest sees their own receipt

There is no account to authorise them, so the transaction id is held in their **session**
(`App\Support\GuestCheckout`) — never matched on `donor_email`, which anyone could type. A different
visitor gets 403 on the same transaction. The receipt is emailed regardless, so the session expiring
costs them nothing.

### Password reset

Hand-rolled auth meant there was no reset flow, and the notification needs `route('password.reset')`.
So `/forgot-password` and `/reset-password/{token}` now exist. `/forgot-password` returns the **same
response whether or not the address is registered** — a different one is an enumeration oracle.

## The flow

```
/give              choose a recurring plan or a one-off amount   (open to guests)
  → /give/details  name, email, phone, address, PAN, purpose   ← collected BEFORE any gateway
  → /give/start    profile updated, Transaction created (pending), handed to the gateway
  → gateway        Razorpay opens inline; Stripe and PayPal redirect
  → /give/{t}/return   browser comes back; payment confirmed server-side
  → POST /webhooks/{gateway}   the authoritative confirmation
```

Donor details are collected on our own form first and then **passed into the gateway's checkout** —
Razorpay `prefill`, Stripe `customer_email` + metadata, PayPal `payer`/`subscriber` — so nobody is
asked for the same thing twice.

## Money

Amounts are stored in **minor units** (paise, cents) as integers, because that is what every gateway
sends and expects, and because floats have no place in a receipt. `App\Support\Money` is the only
place they become human-readable; it also knows that JPY and friends have no minor unit, and formats
INR with Indian grouping (₹1,50,000) rather than Western.

## Receipts

- Numbers are **only assigned when money is actually received**, never on a pending row, and never
  reassigned. Sequential per Indian financial year: `BAC/2026-27/00001`, allocated inside a locked
  transaction so two concurrent webhooks cannot claim the same number.
- Donor details are **snapshotted onto the transaction**, not read from the user at print time. A
  receipt must keep saying what it said when issued, even after the donor edits their profile.
- Rendered by **Gotenberg** — the same service the n8n workflows use, so the emailed receipt and the
  downloaded one come out of one renderer. Set `GOTENBERG_URL`; it defaults to `http://localhost:3000`.
- `/transactions/{t}/receipt` returns 404 for a payment that has not completed. A receipt is proof
  money was received; issuing one for a pending payment would be a false document.

## Webhooks

`POST /webhooks/{razorpay|stripe|paypal}` — outside the CSRF group by necessity (gateways have no
session), authenticated by signature instead:

| Gateway | Verification |
| --- | --- |
| Razorpay | HMAC-SHA256 of the raw body vs `X-Razorpay-Signature` |
| Stripe | `t=…,v1=…` over `timestamp.rawBody`, with a replay window (`STRIPE_WEBHOOK_TOLERANCE`) |
| PayPal | posted back to `/v1/notifications/verify-webhook-signature` — PayPal signs with RSA, not HMAC, so there is nothing to recompute locally |

All three **fail closed**: anything that cannot be positively verified is rejected with 400,
including the case where no webhook secret is configured at all.

`PaymentRecorder` is the single place a payment is marked received, keyed on the gateway's payment id
so replays and retries are safe. Verified: a replayed webhook returns 200 and creates no second row.

Duplicate-event handling differs per gateway and is deliberate:

- **Razorpay** fires `payment.captured` *and* `subscription.charged` for one subscription charge —
  the latter is ignored.
- **Stripe** `checkout.session.completed` covers one-offs and the first subscription charge;
  `invoice.paid` covers renewals, with `billing_reason: subscription_create` ignored so the first
  invoice is not receipted twice.
- **PayPal** only `PAYMENT.CAPTURE.COMPLETED` / `PAYMENT.SALE.COMPLETED`. `CHECKOUT.ORDER.APPROVED`
  and `BILLING.SUBSCRIPTION.ACTIVATED` move no money.

## Plans

A plan lives in **two places**: this app (price, cadence, description) and the gateway itself. This
app only stores the mapping, in `gateway_plan_ids`:

```json
{"razorpay": "plan_...", "stripe": "price_...", "paypal": "P-..."}
```

Admin → Plans has a **Fetch from …** button beside each gateway field that lists the recurring plans
already in that gateway, so ids are picked rather than retyped from another dashboard. It fills a
`datalist`, so the field stays free-text and an id can still be pasted if the call fails.

| Gateway | Listed from | Shows |
| --- | --- | --- |
| Razorpay | `GET /v1/plans` | name, amount, cadence |
| Stripe | `GET /v1/prices?type=recurring` with the product expanded | name, amount, cadence |
| PayPal | `GET /v1/billing/plans` | name only — the list response carries no pricing, and fetching each plan's detail just to fill a dropdown is not worth N requests |

A plan with no id for a gateway simply cannot be subscribed to through it, and the code says so
explicitly rather than failing at the API.

## Configuration

Everything is in `.env`; `config/payments.php` has the shape. A gateway with no keys is **hidden from
the donor**, and one that cannot take the chosen currency is filtered out — the failure would
otherwise happen mid-checkout. PayPal is restricted to USD/EUR/GBP because most Indian accounts
cannot settle INR through it.

## Emailing receipts

Every confirmed payment queues `SendDonationReceipt`, which renders the PDF and emails it to the
donor — one-offs and automatic renewals alike.

**Queued on purpose.** Rendering calls Gotenberg and sending calls SMTP; neither belongs inside a
webhook request, because a gateway that does not get a fast 2xx retries the whole delivery. Run a
worker:

```bash
php artisan queue:work
```

`QUEUE_CONNECTION=database` is set and the jobs table already exists. With `sync`, a slow SMTP server
would delay the webhook response.

The email is multipart — HTML plus a plain-text alternative (which helps deliverability) — with the
logo embedded via `cid` so it renders where remote images are blocked, and the PDF attached as
`Receipt-BAC-2026-27-00001.pdf`.

### Not sending the same receipt twice

**This app and the n8n workflows both email receipts. Exactly one should be turned on**, or donors
get two. Set in `.env`:

```
RECEIPT_EMAIL_ENABLED=false     # n8n owns donor email
RECEIPT_EMAIL_ENABLED=true      # this app owns it — disable the n8n workflows
```

Within the app, `receipt_emailed_at` on the transaction guards against webhook retries, a manual
resend racing the automatic send, and the job being retried after the mail already went out.
`RECEIPT_EMAIL_BCC` takes a comma-separated list if you want a copy on file.

### When delivery fails

The job retries 3 times (30s, 2m, 10m) and writes the reason to `receipt_email_error` on **every**
failed attempt, not just the last — otherwise the admin table would show "Queued" for twelve minutes
with no hint. Admin → Transactions shows Sent / Queued / Failed per row, with a **Resend** button
that clears the guard and re-queues.

## Known gaps
- **`confirmReturn` trusts the browser leg** for Razorpay by verifying its signature, which is
  correct, but the webhook remains the real source of truth. If a webhook never arrives the payment
  stays `pending` and the donor sees "still confirming".
- **PayPal renewals** arrive as `PAYMENT.SALE.COMPLETED` with no reference of ours, so they are
  matched by `billing_agreement_id` against the stored subscription. If that subscription row is
  missing the payment cannot be matched and is logged rather than guessed at.
- **FCRA.** Foreign donations to an Indian trust are regulated, and 80G means nothing to a donor
  paying in USD. `ORG_PAN` / `ORG_80G` print on every receipt regardless of currency — check with
  your auditor before taking foreign money through this.
- **`APP_URL` must be set correctly.** The "View in your dashboard" link in the receipt email is
  built from it, so a wrong value sends donors somewhere that does not exist.
- **Nothing creates a plan inside a gateway.** Admin → Plans maps to plans that already exist there;
  creating them is still done in Razorpay/Stripe/PayPal's own dashboards. Fetching makes the mapping
  safe, not automatic.
- **A guest can type someone else's email.** The receipt then goes to that address, and an account is
  created for it. Requires actually paying, so the incentive is low, and it is how most donation
  forms behave — but if that matters to you, the fix is to verify the address before creating the
  account.
- **The guest address is stored as one line.** `donor_address` is a single string on the transaction,
  so a created account gets it in `address_line1` rather than split into city/state/PIN. The donor can
  correct it in their profile.
- Tests cover access control, guest donations and the receipt-email path (32 tests). The payment
  flows were exercised by hand.

## Verified

- auth redirects, and `/admin` returns **404** to a signed-in non-admin (not 403 — it should not
  confirm it exists)
- donor pages, admin pages, plan chooser, details form all render
- details form prefills from the profile and only offers configured gateways for the currency
- a one-off below the minimum is rejected
- Razorpay webhook: valid signature → 200 and the transaction becomes `paid` with
  `BAC/2026-27/00001`, `Visa credit ••••4366` parsed from the payload; tampered signature → 400;
  missing signature → 400; **replay → 200 with no duplicate row**; unknown gateway → 404
- receipt for an unpaid transaction → 404; another donor's transaction → 403
- **admins redirected off all five donor routes**, donors still reach them, donors get 404 on admin
  pages, and each role's root lands on its own home
- `$user->update(['is_admin' => true])` provably does nothing; `php artisan user:admin` works
- donor detail page renders name, address, per-currency totals, subscriptions and paginated payments
- **Razorpay plan fetch returns the real plan from the configured account**
  (`plan_TMqoLx8p5QI32x`, ₹399, 1× monthly); unconfigured gateways → 422 naming the gateway;
  unknown gateway → 404

**Guest donations**, driven through a real browser session against live Razorpay:

- a guest reached `/give`, submitted the details form, and a **real Razorpay order was created**
  (`order_TWJcORtrsrtXHB`) against a transaction with `user_id = NULL`
- a signed webhook marked it paid, and the queue produced the account: `Meera Devi`, transaction
  linked with `linked_at` set, receipt `BAC/2026-27/10000`, reset token issued
- the emailed message is *Set a password for your Braj Animal Care account* carrying a
  `reset-password/…` link and **no password anywhere in it**
- the paying guest's session could open the transaction and download the PDF (200); a **fresh session
  got 403** on both
- following the reset link set a password, signed the donor in, and the dashboard greeted
  `Meera Devi` showing `₹750.00`
- unit-tested besides: linking to an existing account sends nothing and creates no second user
  (case-insensitively); an existing profile is not overwritten; earlier guest donations are linked;
  an unpaid transaction creates nothing; the new account cannot be signed into by guessing
  (`password`, the email, the name, empty); `/forgot-password` gives an identical response for known
  and unknown addresses
- receipt HTML renders with the right amount, receipt number, donor snapshot, PAN and 80G
- Gotenberg unreachable → a stated error naming the URL and how to fix it, not a raw cURL failure

**Receipt email**, end to end through a queue worker against a stubbed Gotenberg:

- webhook → transaction `paid` → `BAC/2026-27/00002` → job queued → worker sends
- the sent message is `multipart/mixed` → `related` → `alternative`, carrying `text/plain`,
  `text/html`, an `image/jpeg` referenced by a matching `Content-ID`/`cid`, and an
  `application/pdf` attachment whose payload decodes to `%PDF`
- subject and body carry the receipt number, `₹2,500.00`, and `UPI (radha@okhdfcbank)` parsed from
  the payload
- 32 tests pass, including: a retried delivery emails **once**; an unpaid payment is never emailed; a
  transaction with no donor email is skipped; and `RECEIPT_EMAIL_ENABLED=false` stops the app
  queueing anything while still recording the payment
- Gotenberg down → the failure is written to `receipt_email_error` on the first attempt and shows as
  Failed in admin; **Resend** then delivers it once Gotenberg is back

Not verified: live Razorpay/Stripe/PayPal checkouts (no live keys here), real SMTP (the `log` mailer
was used), and real Gotenberg PDF output (a stub returned a valid PDF; the HTML it renders from was
checked separately).
