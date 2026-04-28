/**
 * @file
 * POST pending court booking to cart from the court node full view.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.courtBookingCourtProceed = {
    attach(context) {
      const s = drupalSettings.courtBookingCourtProceed;
      if (!s || !s.addUrl || !s.csrfToken || !s.variation_id || !s.start || !s.end) {
        return;
      }
      once('court-booking-court-proceed', '#court-booking-proceed-to-cart', context).forEach((btn) => {
        btn.addEventListener('click', async (e) => {
          e.preventDefault();
          if (btn.disabled) {
            return;
          }
          btn.disabled = true;
          try {
            const res = await fetch(s.addUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': s.csrfToken,
              },
              body: JSON.stringify({
                variation_id: s.variation_id,
                start: s.start,
                end: s.end,
                quantity: 1,
                redirect_to: 'cart',
              }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
              // eslint-disable-next-line no-alert
              window.alert(data.message || Drupal.t('Could not add to cart. Please try again.'));
              btn.disabled = false;
              return;
            }
            if (data.redirect) {
              window.location.href = data.redirect;
            }
          } catch (err) {
            // eslint-disable-next-line no-alert
            window.alert(Drupal.t('Network error. Try again.'));
            btn.disabled = false;
            // eslint-disable-next-line no-console
            console.error(err);
          }
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
