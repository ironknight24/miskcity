(function (Drupal) {
  Drupal.behaviors.mobileMenuToggle = {
    attach(context) {
      const toggle = context.querySelector('#menu-toggle');
      const close = context.querySelector('#menu-close');
      const mobileMenu = context.querySelector('#mobile-menu');
      const backdrop = context.querySelector('#backdrop');

      if (!toggle || !close || !mobileMenu || !backdrop) return;

      // Toggle open
      for (const toggleBtn of once('mobile-menu-toggle', toggle)) {
        toggleBtn.addEventListener('click', () => {
          mobileMenu.classList.remove('translate-x-full');
          mobileMenu.classList.add('translate-x-0');
          backdrop.classList.remove('hidden');
        });
      }

      // Close via close button
      for (const closeBtn of once('mobile-menu-close', close)) {
        closeBtn.addEventListener('click', () => {
          mobileMenu.classList.add('translate-x-full');
          mobileMenu.classList.remove('translate-x-0');
          backdrop.classList.add('hidden');
        });
      }

      // Close via backdrop
      for (const backdropEl of once('mobile-menu-backdrop', backdrop)) {
        backdropEl.addEventListener('click', () => {
          mobileMenu.classList.add('translate-x-full');
          mobileMenu.classList.remove('translate-x-0');
          backdrop.classList.add('hidden');
        });
      }
    }
  };
})(Drupal);
