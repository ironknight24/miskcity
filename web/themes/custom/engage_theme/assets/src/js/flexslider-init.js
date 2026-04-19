(function ($, Drupal) {
  Drupal.behaviors.flexsliderInit = {
    attach: function (context, settings) {
      $('.flexslider', context).each(function () {
        // To avoid initializing the slider multiple times on the same element,
        // check if it has already been initialized by storing a data flag.
        if (!$(this).data('flexslider-initialized')) {
          $(this).flexslider({
            animation: "slide",
            slideshowSpeed: 5000,
            animationSpeed: 600,
            controlNav: true,
            directionNav: true,
            smoothHeight: true,
          });
          $(this).data('flexslider-initialized', true);
        }
      });
    }
  };
})(jQuery, Drupal);
