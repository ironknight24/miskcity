(function (Drupal, drupalSettings) {
  Drupal.behaviors.profilePictureRemove = {
    attach(context) {

      once('remove-profile-picture', '#remove-profile-picture', context)
        .forEach(btn => {
          btn.addEventListener('click', e => {
            e.preventDefault();
            document.querySelector('#remove-profile-picture-modal')
              ?.classList.remove('hidden');
          });
        });

      once('remove-btn', '#remove-btn', context)
        .forEach(btn => {
          btn.addEventListener('click', e => {
            e.preventDefault();
            document.querySelector('#global-spinner')?.classList.remove('hidden');
            removeProfilePicture();
          });
        });

      function removeProfilePicture() {
        fetch(drupalSettings.globalVariables.webportalUrl + "detailsUpdate", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            endPoint: "detailsUpdate",
            payload: { tenantCode: drupalSettings.globalVariables.ceptenantCode },
            service: "cityapp",
            type: "2"
          })
        })
          .then(res => res.json())
          .then(data => {
            document.querySelector('#global-spinner')?.classList.add('hidden');
            if (data.status) location.reload();
          });
      }
    }
  };
})(Drupal, drupalSettings);

/* ===================== Validation ===================== */

(function ($, Drupal) {
  Drupal.behaviors.profileValidation = {
    attach(context) {
      const $form = $('#profile-form', context);
      if (!$form.length || $form.data('validated')) return;

      $form.data('validated', true);

      $form.validate({
        rules: {
          first_name: { required: true, minlength: 2 },
          last_name: { required: true, minlength: 2 },
          mobile: { required: true, digits: true, minlength: 10, maxlength: 10 }
        },
        errorClass: "text-red-500 text-sm"
      });
    }
  };
})(jQuery, Drupal);
