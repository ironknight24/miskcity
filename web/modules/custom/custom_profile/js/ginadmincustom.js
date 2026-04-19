(function ($, Drupal) {
  Drupal.behaviors.globalModalScrollFix = {
    attach: function (context) {

      // When dialog opens
      $(document).on('dialogopen', function () {
        setTimeout(() => {
          $('#drupal-modal').scrollTop(0);
        }, 100);
      });

      // After AJAX updates inside modal
      $(document).on('ajaxComplete', function () {
        if ($('#drupal-modal:visible').length) {
          setTimeout(() => {
            $('#drupal-modal').scrollTop(0);
          }, 100);
        }
      });

    }
  };
})(jQuery, Drupal);