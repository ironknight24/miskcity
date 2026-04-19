(function ($, Drupal) {
  Drupal.behaviors.autoDismissMessages = {
    attach: function (context, settings) {
      setTimeout(function () {
        $('.messages').fadeOut('slow');
      }, 5000); // 5 seconds
    }
  };
})(jQuery, Drupal);
