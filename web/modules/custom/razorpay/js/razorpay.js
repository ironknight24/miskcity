(function ($, Drupal) {
  'use strict';
  Drupal.behaviors.commerceRazorpayCheckout = {
    attach: function (context, drupalSettings) {
      once('commerceRazorpayCheckout', 'html').forEach(function () {
        var data = drupalSettings.razorpay_checkout_data;
        if (!data) {
          return;
        }

        var setDisabled = function (id, state) {
          if (typeof state === 'undefined') {
            state = true;
          }
          var elem = document.getElementById(id);
          if (!elem) {
            return;
          }
          if (state === false) {
            elem.removeAttribute('disabled');
          }
          else {
            elem.setAttribute('disabled', state);
          }
        };

        $('#msg-razorpay-success').css('background-color', 'yellow');
        $('#msg-razorpay-success').hide();
        setDisabled('btn-razorpay', false);

        data.modal = {
          ondismiss: function () {
            setDisabled('btn-razorpay', false);
          }
        };

        window.onbeforeunload = function () {
          $('#msg-razorpay-success').show();
          setDisabled('btn-razorpay-cancel');
        };

        var razorpayCheckout = new Razorpay(data);

        function rzpOpenCheckout() {
          setDisabled('btn-razorpay');
          razorpayCheckout.open();
        }

        rzpOpenCheckout();

        $('#btn-razorpay').on('click', function (event) {
          event.preventDefault();
          rzpOpenCheckout();
        });

        $('#btn-razorpay-cancel').on('click', function (event) {
          event.preventDefault();
          $('#msg-razorpay-success').hide();
        });
      });
    }
  };
}(jQuery, Drupal));
