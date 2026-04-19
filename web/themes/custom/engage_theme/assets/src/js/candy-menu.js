(function (Drupal, once) {

    Drupal.behaviors.candyMenuToggle = {
        attach(context) {
            const buttons = once('candyMenu', '#candy-button', context);
            if (!buttons.length) {
                return;
            }

            const menu = document.getElementById('candy-menu');
            const wrapper = document.getElementById('candy-wrapper');

            if (!menu || !wrapper) {
                return;
            }

            for (const button of buttons) {
                button.addEventListener('click', (e) => {
                    e.stopPropagation();
                    toggleMenu(menu);
                });
            }

            attachOutsideClickHandler(wrapper, menu);
        }
    };

    function toggleMenu(menu) {
        menu.classList.toggle('hidden');
    }

    function attachOutsideClickHandler(wrapper, menu) {
        document.addEventListener('click', (e) => {
            if (!wrapper.contains(e.target)) {
                menu.classList.add('hidden');
            }
        });
    }

})(Drupal, once);
