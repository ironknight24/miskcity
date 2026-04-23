# API Payment Correction Plan (`example_payment`)

## Goal
Correct the checkout APIs so `example_payment` can be used in an API-driven flow that:
- collects payer/card details,
- confirms payment via trusted server-side signal,
- completes the order in Drupal so it appears in Orders.

## Current Behavior (Root Cause)
- The gateway `example_payment` is configured as `example_stored_offsite_redirect`.
- Current `checkout/complete` logic intentionally returns `422` for offsite gateways and provides `checkout_url`.
- Only zero-balance and manual gateways are auto-completed today.

## Target API Flow
1. Client logs in and builds cart as today.
2. Client submits payment details to a new secure API endpoint (no raw PAN/CVV persistence).
3. Server creates/records a payment session reference for `example_payment`.
4. Client confirms payment through a new/extended confirm endpoint.
5. Server verifies trusted payment success (confirm payload and/or signed webhook state).
6. Server creates `commerce_payment` entity.
7. Server runs existing order finalization (`finalizePlacedOrder`) so state transitions to `completed`.

## Changes Needed

### 1) Routing
Update `web/modules/custom/court_booking/court_booking.routing.yml`:
- Add endpoint for payment details submission (draft order, owner-only).
- Add endpoint for payment confirmation/finalization (draft order, owner-only).

### 2) Controller
Update `web/modules/custom/court_booking/src/Controller/CommerceRestController.php`:
- Add controller methods for:
  - `paymentDetails(...)`
  - `paymentConfirm(...)`
- Reuse current auth/access (`ownDraftOrder`) and JSON validation patterns.

### 3) Service Logic
Update `web/modules/custom/court_booking/src/CommerceCheckoutRestService.php`:
- Keep existing `payAndPlaceOrder()` behavior for:
  - zero-balance,
  - manual gateway.
- Add offsite API-specific methods for `example_payment`:
  - `prepareApiPayment(...)`
  - `confirmApiPayment(...)`
- Ensure order placement happens only after trusted success verification.
- Reuse `finalizePlacedOrder()` to preserve checkout completion behavior and downstream integrations.

### 4) Payment/Webhook Finalization
Enhance webhook success handling in same service:
- On trusted success for tracked session, create payment + finalize order idempotently.
- Keep replay protection (`event_id`) and signature validation intact.

### 5) Safe Storage Rules
- Store only non-sensitive metadata (order_id, session/reference IDs, masked fields, status, timestamps).
- Never persist raw card number or CVV.

### 6) Documentation
Update `web/modules/custom/court_booking/COURT_BOOKING_API.md`:
- Add payment details endpoint (request/response examples).
- Add payment confirm endpoint (request/response examples).
- Update end-to-end flow for `example_payment` API mode.

## Validation Checklist
- Zero-balance orders still complete.
- Manual gateway orders still complete.
- `example_payment` API flow completes order to `completed`.
- Completed orders appear in Drupal Orders tab.
- Payment failure path does not place order.
- Duplicate callbacks/retries do not create duplicate payments or double-place orders.
