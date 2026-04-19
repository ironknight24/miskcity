(function ($, Drupal, once) {
    Drupal.behaviors.fontResize = {
        attach: function (context, settings) {
            $(once('font-resize-init', 'html', context)).each(function () {
                initFontResize();
            });
        }
    };

    function initFontResize() {
        let $html = $('html');
        let baseSize = Number.parseFloat($html.css('font-size')); // Tailwind/browser base size
        let currentStep = 0;
        let minStep = -5;
        let maxStep = 5;
        let stepSize = 1; // px increment

        let $minus = $('#font_resize-minus');
        let $default = $('#font_resize-default');
        let $plus = $('#font_resize-plus');
        let $buttons = $minus.add($default).add($plus);

        // Remove href and set cursor
        $buttons.removeAttr('href').css('cursor', 'pointer');

        // Utility: highlight active button
        function setActive($btn) {
            $buttons.removeClass('active');
            $btn.addClass('active');
        }

        // A- (decrease)
        $(once('font-resize-minus', $minus)).on('click', function (e) {
            e.preventDefault();
            if (currentStep > minStep) {
                currentStep--;
                $html.css('font-size', (baseSize + currentStep * stepSize) + 'px');
                setActive($minus);
            }
        });

        // A (reset)
        $(once('font-resize-default', $default)).on('click', function (e) {
            e.preventDefault();
            currentStep = 0;
            $html.css('font-size', baseSize + 'px');
            setActive($default);
        });

        // A+ (increase)
        $(once('font-resize-plus', $plus)).on('click', function (e) {
            e.preventDefault();
            if (currentStep < maxStep) {
                currentStep++;
                $html.css('font-size', (baseSize + currentStep * stepSize) + 'px');
                setActive($plus);
            }
        });

        // Start with default active
        setActive($default);
    }
})(jQuery, Drupal, once);
