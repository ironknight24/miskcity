# Court booking REST API (`/rest/v1/`)

Mobile and SPA clients authenticate with **OAuth 2** (Simple OAuth). Send:

`Authorization: Bearer <access_token>`

Token endpoint (default): `POST /oauth/token`  
Configure clients, keys, and grants under **Configuration → People → Simple OAuth**.

## OAuth setup (administrator)

1. Enable modules: **Simple OAuth** (pulls **Consumers**), **Commerce Checkout** (already required by this module).
2. Generate public/private keys: **Configuration → People → Simple OAuth** (or use existing keys).
3. Create an **OAuth2 Client** (consumer) for the mobile app with a grant your app supports (e.g. Resource Owner Password Credentials, Authorization Code, or Client Credentials for machine users).
4. Assign the Drupal user account that will book courts the permissions:
   - `use court booking add`
   - `access court booking page` (for read-only availability/slot-candidates), or rely on the add permission
   - `access checkout` (for cart and checkout completion endpoints)

## Court booking endpoints

| Method | Path | Body / query | Notes |
|--------|------|--------------|-------|
| GET | `/rest/v1/court-booking/variations/{variation_id}/availability` | `from`, `to`, optional `interval`, `breakdown` | Same JSON as `GET /commerce-bat/availability/{id}` |
| GET | `/rest/v1/court-booking/variations/{variation_id}/slot` | `start`, `end`, `quantity` | Same as `GET /commerce-bat/check/{id}` → `{ "available": bool }` |
| POST | `/rest/v1/court-booking/slot-candidates` | JSON: `ymd`, `duration_minutes` or `duration_hours`, `variation_ids`, `quantity` | Staggered candidates when buffer &gt; 0 |
| POST | `/rest/v1/court-booking/cart/line-items` | JSON: `variation_id`, `start`, `end`, `quantity` | Adds validated line; response includes `order_id`, `order_item_id`, `total`, `checkout_url` |
| PATCH | `/rest/v1/court-booking/cart/line-items/{order_item_id}` | JSON: `start`, `end` | Cart line must belong to current user’s cart |

Legacy session + CSRF JSON (unchanged): `/court-booking/add`, `/court-booking/slot-candidates`, `/court-booking/cart/slot/{order_item}`.

## Commerce helpers

| Method | Path | Body | Notes |
|--------|------|------|-------|
| GET | `/rest/v1/commerce/cart` | — | Current cart for the **court booking order type** (from `court_booking.settings`). |
| POST | `/rest/v1/commerce/orders/{order_id}/checkout/complete` | See below | Completes checkout for **manual** gateways or **zero balance**. |

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

## Payment gateway audit

Confirm which gateways are enabled in the target environment (`/admin/commerce/config/payment`). Automated API completion is implemented for:

- **Manual** (`ManualPaymentGatewayInterface`)
- **Zero balance** orders

All other types should use web checkout or a future gateway-specific integration.

## Functional checks

- Compare availability for the same variation and `from`/`to` against `GET /commerce-bat/availability/{id}` (anonymous allowed there; REST requires OAuth + permission).
- After `POST .../checkout/complete`, confirm BAT sync: order should reach **completed** (or your workflow’s equivalent) and `commerce_bat_sync_order_events()` should run via existing subscribers.

## Database (reference)

- Variation ↔ BAT unit: Commerce BAT mapping tables (see `commerce_bat` schema).
- Reservations: `bat_event` (and related) after orders are placed/synced.
- Cart lines: `commerce_order_item` with `field_cbat_rental_date`.
