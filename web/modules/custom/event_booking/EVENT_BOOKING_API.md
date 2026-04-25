# Event booking REST API (`/rest/v1/event-booking/`)

This module exposes JSON endpoints for **event ticket** cart, **portal user** verification, **example_payment** checkout (via the same payment logic as `court_booking`), **ticket variation pricing** (Commerce price by `variation_id`), **My Booked Events** list APIs (upcoming/completed tabs), and an **order receipt** that includes the linked **event** node (schedule, location, image). Receipts/lists resolve events by **reverse lookup**: an **event** node whose configured variation reference field points at the line’s purchased variation (see Configuration).

**Base URL in examples:** `http://localhost:8080` — replace with your environment (e.g. production host).

---

## Authentication

### Bearer token (IDAM)

All routes under `/rest/v1/event-booking/*` except where noted use the **`court_booking_bearer`** provider (from the `court_booking` module). Send the same **`access_token`** returned by the public login endpoint:

`Authorization: Bearer <access_token>`

```
POST /rest/v1/auth/login  →  { "access_token": "...", "token_type": "Bearer", ... }
                                          |
                    use this value as the bearer token
                                          ↓
Authorization: Bearer <access_token>  →  /rest/v1/event-booking/...
```

**How the server validates the token (summary):** IDAM `userinfo` → portal `user/details` existence check → active Drupal user by email. Details match [COURT_BOOKING_API.md](../court_booking/COURT_BOOKING_API.md) (“How token validation works”). This module does not re-implement token validation; it relies on that stack.

### Portal `userId` (separate from Bearer)

The value you send in **`portal_user_id`** (see **Portal user verify** below) is **not** the Bearer token. It is the identifier the **city portal** returns for the citizen (the same value `ApiRedirectSubscriber` logs as `$processed['userId']` after calling `user/details` with the logged-in user’s **email** — see [`ApiRedirectSubscriber::callYourApi()`](../global_module/src/EventSubscriber/ApiRedirectSubscriber.php)).

Drupal does **not** invent this value offline. To obtain it for the **current Bearer user** without guessing, call **`GET /rest/v1/event-booking/portal-user/context`** (below): the server POSTs `user/details` with `userId` = the Drupal account email (same as the subscriber) and returns `portal_user_id` from the portal response. You can then pass that value into **Portal user verify** if you want an explicit match check.

### Client tips

- Prefer **no `Cookie` header** on API-only calls (session is not required when Bearer is valid).
- Send `Content-Type: application/json` and `Accept: application/json` on JSON endpoints.

---

## Permissions

| Permission | Who |
|------------|-----|
| `use event booking api` | Required for all `/rest/v1/event-booking/*` routes below. Granted to the **authenticated** role when the module is installed (revoke if undesired). |
| `access checkout` | Required indirectly for payment routes (same as Commerce REST in `court_booking`). |
| `administer event booking` | **Commerce → Event booking API** — store ID, order type, variation defaults, field machine names (`/admin/commerce/config/event-booking`). |

---

## Configuration (defaults)

Adjust in admin if your site differs.

| Setting | Default (example) |
|---------|-------------------|
| Commerce store (Event Store) | `2` |
| Order type | `default` |
| Default ticket `variation_id` | `7` (General Event Ticket) |
| Max quantity per add-to-cart request | `500` |
| Event content type (bundle) | `events` |
| Event node field → ticket variation | `field_prod_event_variation` (entity reference to `commerce_product_variation`; site default in `event_booking.settings`) |
| Legacy fallback: variation → event field | Optional (empty when unused); used only if reverse lookup finds no event |
| Event node date range field | `field_event_date_time` |
| Event node image field | `field_event_image` (core **image** field) |
| Event node location field | `field_event_location` |

---

## Endpoint summary

| Step | Method | Path | Auth |
|------|--------|------|------|
| Login | POST | `/rest/v1/auth/login` | None |
| Portal user context (resolve `userId`) | GET | `/rest/v1/event-booking/portal-user/context` | Bearer |
| Portal user verify | POST | `/rest/v1/event-booking/portal-user/verify` | Bearer |
| Ticket variation pricing | GET | `/rest/v1/event-booking/ticket-variations/{variation_id}/pricing` | Bearer |
| Add tickets | POST | `/rest/v1/event-booking/cart/items` | Bearer |
| Get cart | GET | `/rest/v1/event-booking/cart` | Bearer |
| Clear cart | POST | `/rest/v1/event-booking/cart/clear` | Bearer |
| My booked events (upcoming) | GET | `/rest/v1/event-booking/my-events/upcoming` | Bearer |
| My booked events (completed) | GET | `/rest/v1/event-booking/my-events/completed` | Bearer |
| Unified my bookings (court + event segments) | GET | `/rest/v1/bookings/my` | Bearer |
| Payment details | POST | `/rest/v1/event-booking/orders/{order_id}/payment/details` | Bearer |
| Payment confirm | POST | `/rest/v1/event-booking/orders/{order_id}/payment/confirm` | Bearer |
| Receipt | GET | `/rest/v1/event-booking/orders/{order_id}/receipt` | Bearer |

Payment **details** / **confirm** URLs live under `event-booking` for convenience; they **delegate** to `court_booking`’s `CommerceCheckoutRestService` (same body and behaviour as `/rest/v1/commerce/orders/...`).

---

## Cache rebuild (after deploy)

When you change **routes**, **services**, or **config schema**, rebuild Drupal caches. From the project root (see [Readme.md](../../../../Readme.md) “Development commands”):

```bash
docker compose exec web bash -lc "cd /opt/drupal && vendor/bin/drush cr"
```

---

## Drush: discover IDs and fields

```bash
# Commerce store IDs and labels
drush sqlq "SELECT store_id, name FROM commerce_store_field_data;"

# Product variations (find your ticket SKU / variation_id)
drush sqlq "SELECT variation_id, sku, title FROM commerce_product_variation_field_data;"

# Event node (e.g. Food Festival Bangalore)
drush sqlq "SELECT nid, type, title FROM node_field_data WHERE nid=4990;"

# Confirm field types on the `events` bundle
drush cget field.field.node.events.field_event_date_time field_type
drush cget field.field.node.events.field_event_image field_type
drush cget field.field.node.events.field_event_location field_type
```

---

## Postman environment (suggested)

| Variable | Example |
|----------|---------|
| `base_url` | `http://localhost:8080` |
| `email` | Your test Drupal user email |
| `password` | Your test password |
| `access_token` | Paste after login |
| `portal_user_id` | From **`GET .../portal-user/context`** or your portal app; optional if you only use **verify** after resolving |
| `variation_id` | `7` or omit if default is configured |
| `order_id` | From add-to-cart or get-cart response |

For protected routes: **Authorization → Bearer Token** → `{{access_token}}`.

---

## cURL walkthrough (full flow)

Replace placeholders:

- `YOUR_ACCESS_TOKEN` — from login, or use `$ACCESS_TOKEN` from the shell snippet below.
- `YOUR_PORTAL_USER_ID` — use the value returned by **`GET .../portal-user/context`** (`portal_user_id` field), or another portal-issued id you already store client-side.
- `ORDER_ID` — numeric order id from **Add tickets** or **Get cart**.

### 0. Save `ACCESS_TOKEN` in the shell (optional)

**Git Bash / Linux / macOS** (uses `sed`; no Python required):

```bash
ACCESS_TOKEN=$(curl -s -X POST \
  "${BASE_URL:-http://localhost:8080}/rest/v1/auth/login" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"email\":\"YOUR_EMAIL\",\"password\":\"YOUR_PASSWORD\"}" \
  | sed -n 's/.*"access_token":"\([^"]*\)".*/\1/p')

echo "$ACCESS_TOKEN"
```

Then substitute `"Authorization: Bearer $ACCESS_TOKEN"` in the commands below.

---

### 1. Login (public) — get `access_token`

```bash
curl -s -X POST \
  "http://localhost:8080/rest/v1/auth/login" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "email": "YOUR_EMAIL",
    "password": "YOUR_PASSWORD"
  }'
```

**Example success**

```json
{
  "access_token": "<use as Bearer>",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "...",
  "id_token": "..."
}
```

**Errors:** `400` invalid body, `401` bad credentials, `503` auth service unavailable.

---

### 2. Resolve portal `userId` (GET context)

Retrieves the portal **`userId`** for the **Bearer-authenticated** user by calling the same **`user/details`** integration as [`ApiRedirectSubscriber`](../global_module/src/EventSubscriber/ApiRedirectSubscriber.php): request body uses the Drupal account **email** as `userId`. The JSON response includes the portal’s canonical `portal_user_id` (what you see in logs as `$processed['userId']`).

No request body. Use this when the mobile app needs the id before calling **Portal user verify** or other APIs that expect that value.

```bash
curl -s -X GET \
  "http://localhost:8080/rest/v1/event-booking/portal-user/context" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Accept: application/json"
```

**Example success**

```json
{
  "portal_user_id": "12345",
  "email": "YOUR_EMAIL",
  "source": "portal_user_details",
  "lookup": "drupal_account_email"
}
```

**Errors:** `400` account has no email, `403` portal email does not match Drupal user, `404` no portal profile for this account, `502` portal payload missing verifiable email or `userId`, `503` portal not configured or HTTP failure.

---

### 3. Portal user verify

Confirms that `portal_user_id` belongs to the same person as the Bearer-authenticated Drupal user (via portal `user/details` + email match).

```bash
curl -s -X POST \
  "http://localhost:8080/rest/v1/event-booking/portal-user/verify" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "portal_user_id": "YOUR_PORTAL_USER_ID"
  }'
```

**Example success**

```json
{
  "verified": true,
  "portal_user_id": "YOUR_PORTAL_USER_ID",
  "email": "YOUR_EMAIL"
}
```

**Errors:** `403` portal identity does not match Drupal user, `404` portal user not found, `503` portal or vault not configured / request failed.

---

### 4. Ticket variation pricing (GET)

Returns **Commerce** sale price (and list price when available) for a **published** ticket variation that belongs to the configured **Event Store**. Use this before **Add tickets** when the client knows `variation_id` (e.g. from your event catalog) and needs display pricing. Prices come from the variation entity, not from the optional `field_event_price` on the event node.

```bash
curl -s -X GET \
  "http://localhost:8080/rest/v1/event-booking/ticket-variations/VARIATION_ID/pricing" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Accept: application/json"
```

Replace `VARIATION_ID` with the numeric Commerce product variation id.

**Example success**

```json
{
  "variation_id": 7,
  "title": "General Event Ticket",
  "sku": "EVENT-GENERAL",
  "published": true,
  "price": "500.00 INR",
  "price_number": "500.00",
  "currency_code": "INR",
  "list_price": null,
  "list_price_number": null,
  "list_currency_code": null
}
```

**Errors:** `401` not authenticated, `404` unknown variation id, unpublished variation, or variation not sold in the configured event store, `500` event store not configured.

---

### 5. Add tickets to cart

Uses the **Event Store** and configured **order type**. `variation_id` may be omitted if `default_variation_id` is set in config.

Before adding a line item, the server asks **Commerce Stock** (same stock service configured for the variation’s product type) how much is available for that variation in the **event store** and **current customer** context. The requested `quantity` plus any **existing cart lines for the same `variation_id`** must not exceed that level. Variations that use the **Always in stock** stock service (or are marked always-in-stock on the variation) are not limited by this API check; checkout may still apply other Commerce rules.

```bash
curl -s -X POST \
  "http://localhost:8080/rest/v1/event-booking/cart/items" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "variation_id": 7,
    "quantity": 2
  }'
```

**Example success**

```json
{
  "status": "ok",
  "order_id": 12,
  "order_item_id": 45,
  "variation_id": 7,
  "quantity": 2,
  "total": "1000 INR",
  "checkout_url": "http://localhost:8080/checkout/12/order_information"
}
```

**Errors:** `400` invalid variation / quantity / not sold in event store, `403` variation not in allowed list (if configured), `409` **insufficient stock** (see below), `500` cart failure.

**`409` insufficient stock** — response JSON includes at least:

- `error`: `insufficient_stock`
- `message` — human-readable explanation (sold out vs. only N more can be added)
- `available_quantity` — total stock level reported for the variation (integer)
- `in_cart_quantity` — sum of quantities already on the **draft** event cart for this `variation_id`
- `requested_quantity` — value from the request body
- `remaining_quantity` — how many more could be added before hitting `available_quantity` (`available_quantity - in_cart_quantity`, not less than zero)

Example when nothing is left to sell:

```json
{
  "error": "insufficient_stock",
  "message": "This ticket is sold out or no longer available in stock.",
  "available_quantity": 0,
  "in_cart_quantity": 0,
  "requested_quantity": 2,
  "remaining_quantity": 0
}
```

---

### 6. Get current event cart

```bash
curl -s -X GET \
  "http://localhost:8080/rest/v1/event-booking/cart" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Accept: application/json"
```

**Example success**

```json
{
  "order_id": 12,
  "state": "draft",
  "checkout_step": null,
  "total": "1000 INR",
  "balance": "1000 INR",
  "line_items": [
    {
      "order_item_id": 45,
      "title": "General Event Ticket",
      "quantity": "2"
    }
  ],
  "checkout_url": "http://localhost:8080/checkout/12/order_information"
}
```

**Errors:** `404` no active cart for this user/store/order type.

---

### 6A. Clear current event cart

```bash
curl -s -X POST \
  "http://localhost:8080/rest/v1/event-booking/cart/clear" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{}'
```

**Example success**

```json
{
  "status": "ok",
  "message": "Cart cleared.",
  "order_id": 12,
  "removed_count": 2,
  "remaining_items": 0
}
```

**Errors:** `404` no active event cart, `409` cart is not draft, `500` clear-cart failure.

---

### 6B. My booked events (Upcoming tab)

Lists events actually booked by the authenticated user from their **completed** orders, filtered to events whose schedule is upcoming relative to current server time.

Query params:

- `page` (default `0`)
- `limit` (default `10`, max `50`)
- `q` (optional search; case-insensitive match on title/location/category)

```bash
curl -s -X GET \
  "http://localhost:8080/rest/v1/event-booking/my-events/upcoming?page=0&limit=10&q=music" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Accept: application/json"
```

**Example success**

```json
{
  "rows": [
    {
      "nid": 4990,
      "order_id": 12,
      "order_ids": [12],
      "title": "Chennai Music Fest 2026",
      "event_schedule": {
        "start": "2026-06-26T12:24:57",
        "end": "2026-06-28T17:25:06"
      },
      "location": "Nandanam",
      "image": {
        "url": "http://localhost:8080/sites/default/files/2026-04/music1.jpg",
        "alt": ""
      },
      "fields": {
        "field_event_date_time": [
          {
            "value": "2026-06-26T12:24:57",
            "end_value": "2026-06-28T17:25:06"
          }
        ],
        "field_event_location": [
          {
            "value": "Nandanam"
          }
        ],
        "field_event_image": [
          {
            "target_id": 123,
            "alt": "",
            "title": "",
            "width": 1200,
            "height": 800,
            "url": "http://localhost:8080/sites/default/files/2026-04/music1.jpg"
          }
        ],
        "field_prod_event_variation": [
          {
            "target_id": 7
          }
        ]
      }
    }
  ],
  "pager": {
    "current_page": 0,
    "total_items": "1",
    "total_pages": 1,
    "items_per_page": 10
  }
}
```

---

### 6C. My booked events (Completed tab)

Lists events actually booked by the authenticated user from their **completed** orders, filtered to events whose schedule has ended before current server time.

Query params are the same as Upcoming:
- `page`, `limit`, `q`

```bash
curl -s -X GET \
  "http://localhost:8080/rest/v1/event-booking/my-events/completed?page=0&limit=10&q=workshop" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Accept: application/json"
```

**Example success**

```json
{
  "rows": [],
  "pager": {
    "current_page": 0,
    "total_items": "0",
    "total_pages": 1,
    "items_per_page": 10
  }
}
```

Notes:
- Event linkage uses `event_node_bundle` + `event_ticket_variation_field` (default `field_prod_event_variation`).
- Results are deduplicated by event node id if multiple orders/line-items point to the same event.
- `order_id` is the primary completed order id for the authenticated user's booked event row.
- `order_ids` contains all completed order ids for the authenticated user that reference the event's booked variation(s).
- `fields` is dynamic. It contains current configurable field values from the booked event node, and newly added fields on the `events` content type will appear automatically once populated.
- Detail-on-click can continue using your Views REST endpoint.

---

### 6D. Unified my bookings (Court + Event segments)

Single facade endpoint for mobile consumption. It keeps existing source row schemas and provides independent pagers for court and event segments.

```bash
curl -s -X GET \
  "http://localhost:8080/rest/v1/bookings/my?bucket=upcoming&kind=all&q=padel&sport_tid=3&court_page=0&court_limit=10&event_page=0&event_limit=10" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Accept: application/json"
```

Query params:
- `bucket`: `upcoming` or `past` (event segment maps `past` to completed events)
- `kind`: `all`, `court`, or `event`
- `q`: optional shared search query
- `sport_tid`: optional court-only sport taxonomy filter
- `court_page`, `court_limit`: pagination for court segment
- `event_page`, `event_limit`: pagination for event segment

**Example success**

```json
{
  "bucket": "upcoming",
  "filters": {
    "q": "padel",
    "sport_tid": 3,
    "kind": "all"
  },
  "segments": {
    "court": {
      "rows": [
        {
          "kind": "court",
          "order_id": 21,
          "title": "Padel Court 1"
        }
      ],
      "pager": {
        "current_page": 0,
        "total_items": "1",
        "total_pages": 1,
        "items_per_page": 10
      }
    },
    "event": {
      "rows": [
        {
          "kind": "event",
          "nid": 4990,
          "order_id": 12,
          "title": "Chennai Music Fest 2026"
        }
      ],
      "pager": {
        "current_page": 0,
        "total_items": "1",
        "total_pages": 1,
        "items_per_page": 10
      }
    }
  }
}
```

Notes:
- Existing endpoints remain supported and unchanged:
  - `/rest/v1/court-booking/my-bookings/upcoming`
  - `/rest/v1/court-booking/my-bookings/past`
  - `/rest/v1/event-booking/my-events/upcoming`
  - `/rest/v1/event-booking/my-events/completed`
- Unified response is segmented by domain for easier mobile rendering.

---

### 7. Collect payment details (`example_payment`)

Call after you have a **draft** order with a positive balance. Uses dummy card data for the **commerce_payment_example** gateway (`example_payment`). Only masked metadata is stored server-side.

```bash
curl -s -X POST \
  "http://localhost:8080/rest/v1/event-booking/orders/ORDER_ID/payment/details" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "payment_gateway_id": "example_payment",
    "payment": {
      "card_holder_name": "Test User",
      "card_number": "4111111111111111",
      "cvv": "123",
      "exp_month": 12,
      "exp_year": 2030,
      "billing_email": "YOUR_EMAIL",
      "card_brand": "visa"
    }
  }'
```

**Example success**

```json
{
  "status": "details_collected",
  "order_id": 12,
  "payment_session_id": "pay_........................",
  "gateway": "example_payment",
  "next_action": "confirm_payment"
}
```

Copy **`payment_session_id`** into the next step.

**Errors:** `400` invalid payment JSON, `403` not your order, `404` order missing.

---

### 8. Confirm payment (`example_payment`)

Completes payment and places the order (order should move to **`completed`**).

```bash
curl -s -X POST \
  "http://localhost:8080/rest/v1/event-booking/orders/ORDER_ID/payment/confirm" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "payment_session_id": "pay_PASTE_FROM_STEP_5",
    "payment_status": "captured",
    "gateway_reference": "txn_event_0001"
  }'
```

Allowed **`payment_status`** values (server accepts one of): `authorized`, `captured`, `paid`, `success`.

**Example success**

```json
{
  "order_id": 12,
  "state": "completed",
  "payment_id": 3,
  "message": "Order completed."
}
```

**Errors:** `403` / `404` session or order mismatch, `409` payment not in successful status, `400` missing `payment_session_id`.

---

### 9. Order receipt (completed orders only)

Returns order summary plus **event** payload per line item when an event node (of the configured bundle) **references** the purchased ticket variation via the configured event→variation field **and** the user may view that event. If no matching event exists (and the optional legacy variation→event field does not resolve), the line item omits `event` / `event_nid` — clients should treat **`event` as optional**.

```bash
curl -s -X GET \
  "http://localhost:8080/rest/v1/event-booking/orders/ORDER_ID/receipt" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Accept: application/json"
```

**Example success (shape)**

```json
{
  "order_id": 12,
  "state": "completed",
  "total": "1000 INR",
  "line_items": [
    {
      "order_item_id": 45,
      "title": "General Event Ticket",
      "quantity": "2",
      "variation_id": 7,
      "event_nid": 4990,
      "event": {
        "nid": 4990,
        "title": "Food Festival Bangalore",
        "event_schedule": {
          "start": "2026-06-01T10:00:00",
          "end": "2026-06-01T18:00:00"
        },
        "location": "Example venue text",
        "image": {
          "url": "http://localhost:8080/sites/default/files/...",
          "alt": ""
        }
      }
    }
  ],
  "events": [
    {
      "nid": 4990,
      "title": "Food Festival Bangalore",
      "event_schedule": { "start": "...", "end": "..." },
      "location": "...",
      "image": { "url": "...", "alt": "" }
    }
  ]
}
```

**Errors:** `403` not your order, `404` order not found, `409` order not **completed** yet.

---

## Optional: other Commerce routes

These are **not** prefixed with `/event-booking/` but work on the same draft order if you use another gateway flow:

| Action | Method | Path |
|--------|--------|------|
| Report payment failure | POST | `/rest/v1/commerce/orders/{order_id}/payment/failure` |
| Payment webhook | POST | `/rest/v1/commerce/payments/webhook` |
| Checkout complete (manual / zero balance) | POST | `/rest/v1/commerce/orders/{order_id}/checkout/complete` |

Examples for those paths live in [COURT_BOOKING_API.md](../court_booking/COURT_BOOKING_API.md).

---

## Typical errors (Bearer routes)

| HTTP | Meaning |
|------|--------|
| `401` / `403` | Missing/invalid token, failed IDAM/portal check, or permission denied |
| `400` | Invalid JSON or missing required fields |
| `404` | Cart/order/session not found |
| `409` | Wrong order state (e.g. receipt before completion) |
| `500` / `503` | Server or upstream portal/IDAM misconfiguration |

---

## End-to-end checklist

1. Login → `access_token`  
2. (Optional) **Portal context** → `portal_user_id` for downstream APIs  
3. (Optional) **Portal verify** → `verified: true` with that `portal_user_id`  
4. (Optional) **Ticket variation pricing** → confirm `price` / `currency_code` for your `variation_id`  
5. Add tickets → note `order_id`  
6. Get cart → confirm line items  
7. Payment details → note `payment_session_id`  
8. Payment confirm → `state: completed`  
9. Receipt → event `title`, `event_schedule`, `location`, `image` when the event references that variation
