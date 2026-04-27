# Court booking REST API (`/rest/v1/`)

Mobile and SPA clients authenticate booking-mutating endpoints with a **Bearer token** —
the `access_token` returned by `POST /rest/v1/auth/login` (the WSO2 IDAM login flow
provided by the `login_logout` module). Send it as:

`Authorization: Bearer <access_token>`

**This is the same `access_token` field** that `POST /rest/v1/auth/login` returns in its
JSON response body. No separate token endpoint or Simple OAuth flow is needed.

```
POST /rest/v1/auth/login  →  { "access_token": "...", "id_token": "...", ... }
                                          |
                    use this value as the bearer token
                                          ↓
Authorization: Bearer <access_token>  →  court-booking / commerce endpoints
```

## How token validation works (server side)

The `court_booking_bearer` authentication provider (`CourtBookingBearerAuthProvider`)
performs three steps on every authenticated request. If **any** step fails the request is
rejected — there is no fallback path.

1. **IDAM live check** — sends `POST https://{idamhost}/oauth2/userinfo` with the bearer
   token. WSO2 returns the `sub` claim (the user's email) only for active, non-expired
   tokens. An expired, revoked, or forged token is rejected here.
2. **Portal existence check** — calls the same `tiotcitizenapp` portal API used during the
   login email-step (`POST {apiUrl}/tiotcitizenapp{apiVersion}/user/details`) to confirm
   the user is still registered in the city portal.
3. **Drupal user load** — loads the active Drupal user whose `mail` matches the `sub`
   returned in step 1.

If the token is missing, expired, revoked, or the email is not found in the portal, the
request returns **403 Forbidden** (Drupal treats unauthenticated REST access as forbidden
on routes that require authentication).

Availability endpoints are public and do not require a bearer token.

## Court booking endpoints

| Method | Path | Body / query | Notes |
|--------|------|--------------|-------|
| POST | `/rest/v1/auth/login` | JSON: `email`, `password` | Public login endpoint; returns OAuth token payload for API clients |
| GET | `/rest/v1/court-booking/sports` | — | Full bootstrap payload for app clients (sports, variations, merged booking rules, date strip) |
| GET | `/rest/v1/court-booking/my-bookings/upcoming` | `page`, `limit`, `q` (or `title` alias), optional `sport_tid` | Bearer + `use court booking add`. **Completed** orders only; rows where rental **end** is strictly in the future (`end > now`) (`rows` + `pager`). |
| GET | `/rest/v1/court-booking/my-bookings/past` | same | Same as upcoming, but rental **end** is at-or-before now (`end <= now`). |
| GET | `/rest/v1/court-booking/variations/{variation_id}/availability` | `from`, `to`, optional `interval` | Rule-aware timeslots only from `court_booking.settings` |
| GET | `/rest/v1/court-booking/variations/{variation_id}/slot` | `start`, `end`, `quantity` | Same as `GET /commerce-bat/check/{id}` → `{ "available": bool }` |
| POST | `/rest/v1/court-booking/slot-candidates` | JSON: `ymd`, `duration_minutes` or `duration_hours`, `variation_ids`, `quantity` | Staggered candidates when buffer > 0 |
| POST | `/rest/v1/court-booking/cart/line-items` | JSON: `variation_id`, `start`, `end`, `quantity` | Adds validated line; response includes `order_id`, `order_item_id`, `total`, `checkout_url` |
| POST | `/rest/v1/court-booking/cart/clear` | Optional JSON body `{}` | Clears **all** lines from current user's **draft** court cart; triggers BAT sync. Use `Content-Type: application/json` (or send empty body). |
| PATCH | `/rest/v1/court-booking/cart/line-items/{order_item_id}` | JSON: `start`, `end` | Cart line must belong to current user's cart |
| POST | `/rest/v1/court-booking/cart/line-items/{order_item_id}` | Optional JSON body `{}` | Cancels/removes a draft cart booking line item (no refund automation). **POST** (not DELETE) for broader client/proxy compatibility. |

Legacy session + CSRF JSON (unchanged): `/court-booking/add`, `/court-booking/slot-candidates`, `/court-booking/price-preview`, `/court-booking/cart/slot/{order_item}`.

**POST `/court-booking/price-preview`** — JSON body: `variation_id`, `start`, `end`, optional `quantity` (same UTC semantics as add-to-cart). Returns `total_formatted`, `total_number`, `currency_code`, `billing_units`, etc., after the same validation as add-to-cart (no cart write). Session + `X-CSRF-Token` header. The **GET `/rest/v1/court-booking/sports`** bootstrap includes **`pricePreviewUrl`** (absolute) for the same endpoint when the app shares the session cookie.

## Commerce helpers

| Method | Path | Body | Notes |
|--------|------|------|-------|
| GET | `/rest/v1/commerce/cart` | — | Current cart for the **court booking order type** (from `court_booking.settings`). Line item `rental` includes UTC storage (`value` / `end_value`) plus `start` / `end` in the effective display timezone (see step 8). |
| POST | `/rest/v1/commerce/orders/{order_id}/checkout/complete` | See below | Completes checkout for **manual** gateways or **zero balance**. |
| POST | `/rest/v1/commerce/orders/{order_id}/payment/failure` | JSON: `gateway`, `code`, `message`, optional `raw` | Authenticated client-reported payment failure audit endpoint (cancel-only policy). |
| POST | `/rest/v1/commerce/payments/webhook` | JSON webhook payload + signature headers | Gateway callback endpoint, idempotent and signature-validated. |

### Checkout complete body

```json
{
  "payment_gateway_id": "manual",
  "manual_received": true
}
```

- **`payment_gateway_id`**: Machine ID of a `commerce_payment_gateway` on the site (see **Commerce → Configuration → Payment gateways**).
- **`manual_received`**: Optional; default `true` for the core **Manual** gateway (marks payment completed). Set `false` for pending (e.g. cash on delivery) if your workflow expects that.

**Off-site gateways** (redirect to bank, etc.) return HTTP `422` with a `checkout_url` — complete payment in a browser/WebView using the normal checkout flow.

**On-site gateways** (card entry with stored payment methods) return HTTP `501` with `checkout_url` until a gateway-specific integration is added.

**Zero balance** orders complete without `payment_gateway_id`.

## Cancellation and payment-failure APIs

Copy-pastable examples for these flows also appear as **steps 10–12** in the [cURL examples](#curl-examples) section below (next to the rest of the numbered checkout flow).

### Cancel a draft booking line item

```bash
curl -X POST \
  "http://localhost:8080/rest/v1/court-booking/cart/line-items/12" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{}'
```

Returns `200` on success with order and line-item identifiers.  
Returns `409` if the order is no longer draft (placed/completed/canceled).

### Report payment failure (client-reported)

```bash
curl -X POST \
  "http://localhost:8080/rest/v1/commerce/orders/5/payment/failure" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "gateway": "example_payment",
    "code": "DECLINED",
    "message": "Card declined by issuer",
    "raw": {
      "transaction_id": "abc-123"
    }
  }'
```

This endpoint records failure details and may move the order to canceled if the cancel transition is available.

### Payment webhook callback

Headers required:
- `X-Payment-Timestamp` (unix epoch seconds)
- `X-Payment-Signature` (hex HMAC-SHA256 of `<timestamp>.<raw_body>` using server secret)

```bash
curl -X POST \
  "http://localhost:8080/rest/v1/commerce/payments/webhook" \
  -H "Content-Type: application/json" \
  -H "X-Payment-Timestamp: 1777777777" \
  -H "X-Payment-Signature: YOUR_HEX_HMAC_SIGNATURE" \
  -d '{
    "event_id": "evt_1001",
    "order_id": 5,
    "status": "failed",
    "gateway": "example_payment",
    "code": "EXPIRED",
    "message": "Payment session expired"
  }'
```

Webhook events are idempotent by `event_id`.

### Refund policy

- Cancellation endpoints are **cancel-only**.
- No automatic refund is triggered by these APIs.
- Refunds remain out-of-band/manual or gateway-managed.

## cURL examples

Replace `YOUR_ACCESS_TOKEN` with the `access_token` from your login response, and adjust
the numeric IDs (`1`, `2`, etc.) to match your actual variation / order / order-item IDs.
Base URL: `http://localhost:8080` (matches the Docker Compose setup).

Steps **10–12** cover **removing a draft cart line**, **client-reported payment failure**, and the **gateway payment webhook** (the same endpoints summarized earlier under *Cancellation and payment-failure APIs*).

### Test credentials

The following IDAM account is available for local / dev testing:

| Field | Value |
|---|---|
| `email` | `eswartrinitynew@gmail.com` |
| `password` | `Trinity@123` |

Use these in step 1 (login) to obtain a real `access_token` for all subsequent calls.

> **Note:** Do not use these credentials in production or commit them in environment-specific config files.

### Postman quick setup

Create a Postman environment with:

- `base_url` = `http://localhost:8080`
- `email` = `eswartrinitynew@gmail.com`
- `password` = `Trinity@123`
- `access_token` = `<value from login response — paste after running step 1>`
- `variation_id` = `1`
- `order_id` = `5`
- `order_item_id` = `12`

For protected endpoints, set:

- Authorization tab -> Type: **Bearer Token**
- Token: `{{access_token}}`

For public endpoints (availability/slot/slot-candidates), keep Authorization as **No Auth**.

For availability requests:

- Required query params: `from`, `to` (YYYY-MM-DD).
- Optional `interval`: numeric minutes (e.g. `60`) or ISO duration (e.g. `PT60M`).
- If `interval` is omitted, API defaults to `commerce_bat.settings.lesson_slot_length_minutes`.

---

### 1. Login (public) and get access token

The `access_token` returned here is the **same token** used as the bearer token for every
protected endpoint below. Call this first, copy the `access_token` value, and supply it as
`Authorization: Bearer <access_token>` on all subsequent calls.

```bash
curl -X POST \
  "http://localhost:8080/rest/v1/auth/login" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "email": "eswartrinitynew@gmail.com",
    "password": "Trinity@123"
  }'
```

**Successful response**

```json
{
  "access_token": "<copy this value for all subsequent requests>",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "....",
  "id_token": "...."
}
```

Save `access_token` in a shell variable for convenience:

```bash
ACCESS_TOKEN=$(curl -s -X POST \
  "http://localhost:8080/rest/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"eswartrinitynew@gmail.com","password":"Trinity@123"}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")
echo $ACCESS_TOKEN
```

Then replace `YOUR_ACCESS_TOKEN` in all examples below with `$ACCESS_TOKEN`.

**Error responses**

- `400`: missing/invalid JSON body fields
- `401`: invalid credentials
- `503`: upstream authentication service unavailable

---

### 2. Get sports bootstrap data (public)

```bash
curl -X GET \
  "http://localhost:8080/rest/v1/court-booking/sports" \
  -H "Accept: application/json"
```

This response mirrors booking page bootstrap data for mobile:
- `sports[]` with court variations and pricing
- per-sport `booking` rules (hours, max duration, buffer, blackout dates, resource closures)
- `dates[]` date strip and regional metadata (`timezone`, locale, first day of week)

Each variation’s pricing bootstrap may include **`hasTieredPricing`**, **`hasDynamicPricingRules`**, and **`dynamicPricingRuleTypes`** (today only **`time_band`** — peak/weekend schedule from variation surcharge fields) so clients can adjust copy; the authoritative price for a slot remains **POST `/court-booking/price-preview`** (or cart APIs).

---

### 2A. My court bookings — upcoming and past (authenticated)

These list **completed** Commerce orders for the configured court booking order type. Each row is one order line with a BAT rental range. **Upcoming** means the rental **end** is strictly after current server time (`end > now`); **past** means the rental **end** is at-or-before now (`end <= now`).

Query params (both endpoints): `page` (default `0`), `limit` (default `10`, max `50`), `q` (optional search on title and location), `title` (backward-compatible alias for `q`), `sport_tid` (optional; same taxonomy id as `sports[].id`).

`q` takes precedence. If both `q` and `title` are sent, the API applies `q` and ignores `title`.

`rental` on each row includes UTC storage (`value` / `end_value`), `timezone`, and display instants `start` / `end` (ISO-8601 with offset), same semantics as `GET /rest/v1/commerce/cart`.

```bash
curl -s -X GET \
  "http://localhost:8080/rest/v1/court-booking/my-bookings/upcoming?page=0&limit=10&q=padel&sport_tid=3" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Accept: application/json"
```

```bash
curl -s -X GET \
  "http://localhost:8080/rest/v1/court-booking/my-bookings/past?page=0&limit=10" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Accept: application/json"
```

```bash
curl -s -X GET \
  "http://localhost:8080/rest/v1/court-booking/my-bookings/upcoming?page=0&limit=10&title=Padel%20Court%201" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Accept: application/json"
```

Unified court + event feed (separate pagers): `GET /rest/v1/bookings/my` — documented in `event_booking` **EVENT_BOOKING_API.md**.

---

### 3. Get court availability calendar

```bash
curl -X GET \
  "http://localhost:8080/rest/v1/court-booking/variations/1/availability?from=2026-05-01&to=2026-05-31" \
  -H "Accept: application/json"
```

**Query parameters**

| Param | Example | Notes |
|---|---|---|
| `from` | `2026-05-01` | Start date (YYYY-MM-DD) |
| `to` | `2026-05-31` | End date (YYYY-MM-DD) |
| `interval` | `60` or `PT60M` | Optional – play duration for each returned slot |

**Availability behavior**

- Response is filtered to rule-valid slots only.
- Rules come from merged `court_booking.settings` (global plus sport override for the variation).
- API enforces booking hours, same-day cutoff, blackout dates, resource closures, and buffer behavior.
- When buffer is configured, slot cadence follows `interval + buffer`.

**Example response**

```json
{
  "variation_id": 2,
  "timezone": "Asia/Kolkata",
  "interval": "PT60M",
  "interval_minutes": 60,
  "buffer_minutes": 0,
  "from": "2026-05-23",
  "to": "2026-05-23",
  "slots": [
    {
      "start": "2026-05-23T00:30:00Z",
      "end": "2026-05-23T01:30:00Z",
      "ymd": "2026-05-23"
    }
  ]
}
```

**Invalid interval response**

```json
{
  "error": "Invalid interval"
}
```

---

### 4. Check a specific slot availability

```bash
curl -X GET \
  "http://localhost:8080/rest/v1/court-booking/variations/1/slot?start=2026-05-10T09:00:00&end=2026-05-10T10:00:00&quantity=1" \
  -H "Accept: application/json"
```

**Response:** `{ "available": true }` or `{ "available": false }`

---

### 5. Get slot candidates for a day

```bash
curl -X POST \
  "http://localhost:8080/rest/v1/court-booking/slot-candidates" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "ymd": "2026-05-10",
    "duration_minutes": 60,
    "variation_ids": [1, 2],
    "quantity": 1
  }'
```

Use `duration_hours` instead of `duration_minutes` if you prefer:

```bash
  -d '{
    "ymd": "2026-05-10",
    "duration_hours": 1,
    "variation_ids": [1],
    "quantity": 1
  }'
```

---

### 6. Add a court booking to cart

```bash
curl -X POST \
  "http://localhost:8080/rest/v1/court-booking/cart/line-items" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "variation_id": 1,
    "start": "2026-05-10T09:00:00",
    "end": "2026-05-10T10:00:00",
    "quantity": 1
  }'
```

**Successful response:**

```json
{
  "status": "ok",
  "order_id": 5,
  "order_item_id": 12,
  "total": "INR 500.00",
  "checkout_url": "http://localhost:8080/checkout/5/order_information"
}
```

---

### 7. Update slot on an existing cart line item

Replace `12` with the `order_item_id` from the add-to-cart response above.

```bash
curl -X PATCH \
  "http://localhost:8080/rest/v1/court-booking/cart/line-items/12" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "start": "2026-05-10T10:00:00",
    "end": "2026-05-10T11:00:00"
  }'
```

---

### 7A. Clear entire court cart (draft only)

Removes **all** order line items from the authenticated user’s active **court booking** cart (`court_booking.settings` → `order_type_id`). Only **`draft`** carts are cleared (`409` otherwise). After save, `commerce_bat_sync_order_events` runs when available so court availability stays consistent.

Prefer **`Content-Type: application/json`** with body `{}`. An **empty** POST body is also accepted. Avoid `Content-Type: text/plain` unless the body is valid JSON (e.g. `{}`), otherwise JSON decoding may fail with `400`.

```bash
curl -X POST \
  "http://localhost:8080/rest/v1/court-booking/cart/clear" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{}'
```

**Successful response (`200`)**

```json
{
  "status": "ok",
  "message": "Cart cleared.",
  "order_id": 5,
  "removed_count": 2,
  "remaining_items": 0
}
```

**Errors**

- `401`: not authenticated.
- `404`: no active court cart for this user/store.
- `409`: cart exists but is not `draft`.
- `500`: unexpected failure while clearing.

---

### 8. Get current cart

```bash
curl -X GET \
  "http://localhost:8080/rest/v1/commerce/cart" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Accept: application/json"
```

**Rental times**

- `value` and `end_value` are the **raw stored** rental range (Commerce BAT / field storage in **UTC**), unchanged for backward compatibility.
- `timezone` is the IANA id used for display (same rules as the booking UI: per-user timezone when configurable and set, otherwise site default from **Configuration » Regional and language » Regional settings**).
- `start` and `end` are the **same instants** expressed as ISO-8601 strings **with numeric offset** in that `timezone` (use these for UI that should match local wall clock).

**Successful response:**

```json
{
  "order_id": 5,
  "state": "draft",
  "checkout_step": "order_information",
  "total": "INR 500.00",
  "balance": "INR 500.00",
  "line_items": [
    {
      "order_item_id": 12,
      "title": "Badminton Court A",
      "quantity": "1",
      "rental": {
        "value": "2026-05-10T04:30:00",
        "end_value": "2026-05-10T05:30:00",
        "timezone": "Asia/Kolkata",
        "start": "2026-05-10T10:00:00+05:30",
        "end": "2026-05-10T11:00:00+05:30"
      }
    }
  ],
  "checkout_url": "http://localhost:8080/checkout/5/order_information"
}
```

---


### 9. Collect payment details (`example_payment` API flow)

Use this endpoint before checkout complete when `payment_gateway_id=example_payment`.
The API validates and stores only masked/non-sensitive metadata.

```bash
curl -X POST \
  "http://localhost:8080/rest/v1/commerce/orders/5/payment/details" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "payment_gateway_id": "example_payment",
    "payment": {
      "card_holder_name": "Eswar Trinity",
      "card_number": "4111111111111111",
      "cvv": "123",
      "exp_month": 12,
      "exp_year": 2030,
      "billing_email": "eswartrinitynew@gmail.com",
      "card_brand": "visa"
    }
  }'
```

**Successful response:**

```json
{
  "status": "details_collected",
  "order_id": 5,
  "payment_session_id": "pay_abc123...",
  "gateway": "example_payment",
  "next_action": "confirm_payment"
}
```

---

### 10. Confirm payment (`example_payment` API flow)

Use the `payment_session_id` returned by step 9.

```bash
curl -X POST \
  "http://localhost:8080/rest/v1/commerce/orders/5/payment/confirm" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "payment_session_id": "pay_abc123...",
    "payment_status": "captured",
    "gateway_reference": "txn_1001"
  }'
```

**Successful response:**

```json
{
  "order_id": 5,
  "state": "completed",
  "payment_id": 44,
  "message": "Order completed."
}
```

---

### 11. Complete checkout (manual payment gateway / zero balance)

Replace `5` with your `order_id`. The `payment_gateway_id` must match a gateway
machine name configured under **Commerce → Configuration → Payment gateways**.

```bash
curl -X POST \
  "http://localhost:8080/rest/v1/commerce/orders/5/checkout/complete" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "payment_gateway_id": "example_payment",
    "manual_received": true
  }'
```

**Zero-balance order (no payment gateway needed):**

```bash
curl -X POST \
  "http://localhost:8080/rest/v1/commerce/orders/5/checkout/complete" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{}'
```

**Successful response:**

```json
{
  "order_id": 5,
  "state": "completed",
  "message": "Order completed."
}
```

---

### 12. Remove a draft cart line item (cancel)

Same path shape as PATCH, but **POST** cancels the line. Same bearer auth and `order_item_id`. Only **draft** cart orders return `200`; non-draft returns `409`. Body may be `{}` or omitted if your client sends `Content-Type: application/json`.

```bash
curl -X POST \
  "http://localhost:8080/rest/v1/court-booking/cart/line-items/12" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{}'
```

---

### 13. Report payment failure (authenticated client)

Records failure on the user’s **own draft** order (same access as checkout complete). Optional `raw` is any JSON-serializable object for audit.

```bash
curl -X POST \
  "http://localhost:8080/rest/v1/commerce/orders/5/payment/failure" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "gateway": "example_payment",
    "code": "DECLINED",
    "message": "Card declined by issuer",
    "raw": {
      "transaction_id": "abc-123"
    }
  }'
```

---

### 14. Payment webhook (gateway → server)

No bearer token. Requires `court_booking_webhook_secret` in Drupal settings. Signature = **hex** HMAC-SHA256 of `<X-Payment-Timestamp>.<exact raw request body>` with that secret. Timestamp must be within **±300 seconds** of server time.

Use a **here-doc or file** so the body bytes used for signing match the body sent:

```bash
BODY='{"event_id":"evt_1001","order_id":5,"status":"failed","gateway":"example_payment","code":"EXPIRED","message":"Payment session expired"}'
TS=$(date +%s)
SIG=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "YOUR_WEBHOOK_SECRET" | awk '{print $2}')
curl -X POST \
  "http://localhost:8080/rest/v1/commerce/payments/webhook" \
  -H "Content-Type: application/json" \
  -H "X-Payment-Timestamp: ${TS}" \
  -H "X-Payment-Signature: ${SIG}" \
  -d "$BODY"
```

`event_id` must be unique per logical event; replays return `200` with `status: duplicate`.

---

### Typical end-to-end flow

```
1. Login via the site → capture `access_token` from login response
2. GET  /rest/v1/court-booking/sports                          → load sports/courts/rules
3. GET  /rest/v1/court-booking/variations/{id}/availability    → pick a date range
4. POST /rest/v1/court-booking/slot-candidates                 → pick an open slot
5. GET  /rest/v1/court-booking/variations/{id}/slot            → confirm slot is free
6. POST /rest/v1/court-booking/cart/line-items                 → add to cart → get order_id
7. GET  /rest/v1/commerce/cart                                 → review cart
8. POST /rest/v1/commerce/orders/{order_id}/payment/details    → collect payment metadata (example_payment)
9. POST /rest/v1/commerce/orders/{order_id}/payment/confirm    → confirm + place order (example_payment)
10. POST /rest/v1/commerce/orders/{order_id}/checkout/complete → place order (manual gateway / zero-balance)
```

Optional / error paths:

```
POST   /rest/v1/court-booking/cart/clear                               → clear entire draft cart (all lines)
POST   /rest/v1/court-booking/cart/line-items/{order_item_id}           → drop a draft line (step 12)
POST   /rest/v1/commerce/orders/{order_id}/payment/failure              → client-reported decline (step 13)
POST   /rest/v1/commerce/payments/webhook                               → gateway callback (step 14)
```

---

## Required Drupal permissions for the authenticated user

| Permission | Required for |
|---|---|
| _None_ | GET availability, slot check, slot candidates (public) |
| `use court booking add` | POST add line, POST cart clear, PATCH slot, POST `…/line-items/{id}` remove draft line |
| `access checkout` | GET cart, POST checkout complete |

## Payment gateway audit

Confirm which gateways are enabled in the target environment (`/admin/commerce/config/payment`). Automated API completion is implemented for:

- **Manual** (`ManualPaymentGatewayInterface`)
- **Zero balance** orders

All other types should use web checkout or a future gateway-specific integration.

## Functional checks

- Validate availability against admin booking rules on `/admin/commerce/config/court-booking` (hours, buffer, blackout dates, resource closures).
- After `POST .../checkout/complete`, confirm BAT sync: order should reach **completed** (or your workflow's equivalent) and `commerce_bat_sync_order_events()` should run via existing subscribers.

## Database (reference)

- Variation ↔ BAT unit: Commerce BAT mapping tables (see `commerce_bat` schema).
- Reservations: `bat_event` (and related) after orders are placed/synced.
- Cart lines: `commerce_order_item` with `field_cbat_rental_date`.
