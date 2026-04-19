(function ($, Drupal) {
  Drupal.behaviors.addFamilyMemberValidation = {
    attach: function (context, settings) {
      // Ensure jQuery Validate is loaded
      if ($.validator === 'undefined') {
        console.error('jQuery Validate is not loaded!');
        return;
      }

      let $form = $('#add-family-member-form', context);

      // Prevent double initialization
      if ($form.data('validated')) return;
      $form.data('validated', true);

      // Custom file size method
      $.validator.addMethod("filesize", function (value, element, param) {
        if (element.files.length === 0) return true;
        return this.optional(element) || (element.files[0].size <= param);
      }, "File size must be less than 2MB");

      // Custom file extension method
      $.validator.addMethod("extensionFile", function (value, element, param) {
        if (element.files.length === 0) return true;
        let allowed = param.split('|');
        let fileName = element.files[0].name.toLowerCase();
        for (const ext of allowed) {
          if (fileName.endsWith(ext)) {
            return true;
          }
        }
        return false;
      }, "Invalid file type");

      // Initialize validation
      $form.validate({
        rules: {
          first_name: { required: true, minlength: 2 },
          calendar: { required: true, dateISO: true },
          gender: { required: true },
          relations: { required: true },
          phone_number: { required: true, digits: true, minlength: 10, maxlength: 10 },
          email: { required: true, email: true },
          "files[upload_file]": { required: true, extensionFile: "jpg|jpeg|png", filesize: 2097152 },
          terms: { required: true }
        },
        messages: {
          first_name: { required: "Name is required", minlength: "At least 2 characters" },
          calendar: { required: "Date of birth is required", dateISO: "Enter a valid date" },
          gender: { required: "Please select gender" },
          relations: { required: "Please select relationship" },
          phone_number: { required: "Mobile number is required", digits: "Only digits allowed", minlength: "Must be 10 digits", maxlength: "Must be 10 digits" },
          email: { required: "Email is required", email: "Enter a valid email" },
          "files[upload_file]": { required: "Please upload a file", extensionFile: "Allowed: JPG, JPEG, PNG", filesize: "File must be ≤ 2MB" },
          terms: "You must agree to the Terms and Conditions"
        },
        errorClass: "text-red-500 text-sm mt-1 block",
        errorPlacement: function (error, element) {
          if (element.attr("type") === "checkbox") {
            error.insertAfter(element.closest('div'));
          } else {
            error.insertAfter(element);
          }
        },
        highlight: function (element) { $(element).addClass("border-red-500"); },
        unhighlight: function (element) { $(element).removeClass("border-red-500"); }
      });
    }
  };
})(jQuery, Drupal);