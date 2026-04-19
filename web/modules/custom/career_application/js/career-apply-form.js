(function ($, Drupal) {
  Drupal.behaviors.careerApplyValidation = {
    attach: function (context, settings) {
      // Ensure jQuery Validate is loaded
      if (typeof $.fn.validate !== 'function') {
        console.error('jQuery Validate is not loaded!');
        return;
      }

      let $form = $('#career-apply-form', context);

      // Prevent double initialization
      if ($form.data('validated')) return;
      $form.data('validated', true);

      // Custom file size validation
      $.validator.addMethod("filesize", function (value, element, param) {
        if (element.files.length === 0) return true;
        return this.optional(element) || (element.files[0].size <= param);
      }, "File size must be less than 5MB");

      // Custom file extension validation
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
          last_name: { required: true, minlength: 2 },
          email: { required: true, email: true },
          mobile: { required: true, digits: true, minlength: 10, maxlength: 10 },
          gender: { required: true },
          "files[resume]": {
            required: true,
            extensionFile: "pdf|doc|docx",
            filesize: 5242880 // 5MB
          }
        },
        messages: {
          first_name: { required: "First name is required", minlength: "At least 2 characters" },
          last_name: { required: "Last name is required", minlength: "At least 2 characters" },
          email: { required: "Email is required", email: "Enter a valid email address" },
          mobile: {
            required: "Mobile number is required",
            digits: "Only digits allowed",
            minlength: "Must be 10 digits",
            maxlength: "Must be 10 digits"
          },
          gender: { required: "Please select gender" },
          "files[resume]": {
            required: "Please upload your resume",
            extensionFile: "Allowed: PDF, DOC, DOCX",
            filesize: "File must be ≤ 5MB"
          }
        },
        errorClass: "text-red-500 text-sm mt-1 block",
        errorPlacement: function (error, element) {
          if (element.attr("type") === "checkbox" || element.is("select")) {
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
