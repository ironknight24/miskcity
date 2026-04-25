# Razorpay (Drupal 11)

This module ports the legacy `drupal_commerce_razorpay` gateway into a Drupal 11-first module.

## Compatibility bridge

- Keeps payment gateway plugin id as `razorpay` so existing Commerce gateway entities continue to resolve.
- Provides legacy route-name aliases:
  - `drupal_commerce_razorpay.capturePayment`
  - `drupal_commerce_razorpay.ipn_handler`
- Mirrors webhook flag config between:
  - `drupal_commerce_razorpay.settings`
  - `razorpay.settings`

## Recommended migration sequence

1. Keep both modules present in staging.
2. Enable `razorpay` and run `drush updb -y`.
3. Verify checkout return + notify webhook flows.
4. Switch production traffic after callback validation.
5. Decommission legacy module only after monitoring confirms stable payment lifecycle updates.
