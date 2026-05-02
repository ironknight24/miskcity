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

  function deleteUser() {
    const payload = {
      endPoint: "deleteUserAccount",
      payload: {
        tenantCode: drupalSettings.globalVariables.ceptenantCode
      },
      service: "tiotweb",
      type: "delyUser"
    };

    fetch(drupalSettings.globalVariables.webportalUrl + "postData", {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify(payload)
    })
      .then(response => response.json())
      .then(data => {
        console.log("Delete response:", data);
        if (data.status === true) {
          const loading = document.querySelector(".loading");
          if (loading) loading.classList.remove("hidden");

          window.location.href = drupalSettings.globalVariables.webportalUrl + "/logout";
        } else {
          document.querySelector('.deleteModalDiv')?.classList.add('hidden');
          document.querySelector('#deleteAccount')?.classList.remove('hidden');
        }
      })
      .catch(error => {
        console.error("Delete request failed:", error);
      });
  };

  Drupal.behaviors.profileDeleteAccountConfirm = {
    attach(context) {
      once('profile-delete-confirm', '.confirm-account-btn', context).forEach(
        (deleteButton) => {
          const modal = deleteButton.closest('#delete-account-input-modal');
          const deleteInput = modal
            ? modal.querySelector('#delete')
            : document.querySelector('#delete');

          if (!deleteInput) {
            return;
          }

          deleteButton.addEventListener('click', function (event) {
            const inputValue = deleteInput.value.trim();

            if (inputValue !== 'DELETE') {
              event.preventDefault();
            }

            deleteUser();
          });
        },
      );
    },
  };

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

(function ($, Drupal) {
  Drupal.behaviors.profileValidation = {
    attach: function (context, settings) {
      if (typeof $.validator === 'undefined') {
        console.error('jQuery Validate is not loaded!');
        return;
      }

      var $form = $('#profile-form', context);
      if (!$form.length) return;
      if ($form.data('validated')) return;
      $form.data('validated', true);
      console.log("Form", $form);

      // ── Custom validators ──────────────────────────────────────────
      $.validator.addMethod("noFutureDate", function (value, element) {
        if (!value) return true; // let `required` handle empty
        var entered = new Date(value);
        var today   = new Date();
        today.setHours(0, 0, 0, 0);
        return entered <= today;
      }, "Date of birth cannot be a future date");

      $.validator.addMethod("notBlank", function (value, element) {
        return value.trim().length > 0;
      }, "This field cannot be blank or spaces only");
      // ──────────────────────────────────────────────────────────────

      $form.validate({
        rules: {
          first_name: { required: true, minlength: 2, maxlength: 50, notBlank: true },
          last_name:  { required: true, minlength: 2, maxlength: 50, notBlank: true },
          dob:        { required: true, date: true, noFutureDate: true },
          gender:     { required: true },
          mobile:     { required: true, digits: true, minlength: 10, maxlength: 10 },
          email:      { required: true, email: true },
          address:    { required: true, minlength: 5, maxlength: 50, notBlank: true }
        },
        messages: {
          first_name: { required: "First name is required",  minlength: "Must be at least 2 characters", maxlength: "Max 50 characters",  notBlank: "First name cannot be spaces only" },
          last_name:  { required: "Last name is required",   minlength: "Must be at least 2 characters", maxlength: "Max 50 characters",  notBlank: "Last name cannot be spaces only" },
          dob:        { required: "Date of birth is required", date: "Invalid date",                     noFutureDate: "Date of birth cannot be a future date" },
          gender:     { required: "Please select gender" },
          mobile:     { required: "Mobile number is required", digits: "Only digits allowed",            minlength: "Must be 10 digits", maxlength: "Must be 10 digits" },
          email:      { required: "Email is required",        email: "Enter a valid email address" },
          address:    { required: "Address is required",      minlength: "At least 5 characters",        maxlength: "Max 50 characters", notBlank: "Address cannot be spaces only" }
        },
        errorClass: "text-red-500 text-sm mt-1 block",
        errorPlacement: function (error, element) {
          if (element.attr("type") === "checkbox") {
            error.insertAfter(element.closest('div'));
          } else {
            error.insertAfter(element);
          }
        },
        highlight:   function (element) { $(element).addClass("border-red-500"); },
        unhighlight: function (element) { $(element).removeClass("border-red-500"); }
      });

      // Override Drupal AJAX beforeSubmit
      if (typeof Drupal.Ajax !== 'undefined') {
        Drupal.Ajax.prototype.beforeSubmit = function (form_values, element_settings, options) {
          var validateAll = 1;
          console.log("wjknwejkw", validateAll);
          if (typeof this.$form !== 'undefined' &&
              (validateAll === 1 || $(this.$form).hasClass('cv-validate-before-ajax')) &&
              $(this.element).attr("formnovalidate") === undefined) {

            $(this.$form).removeClass('ajax-submit-prevented');
            $(this.$form).validate();
            if (!($(this.$form).valid())) {
              this.ajaxing = false;
              $(this.$form).addClass('ajax-submit-prevented');
              console.log(this.$form);
              return false;
            }
          }
        };
      }
    }
  };
})(jQuery, Drupal);
