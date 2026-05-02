// (function ($, Drupal) {
//   Drupal.behaviors.careerApplyValidation = {
//     attach: function (context, settings) {
//       // Ensure jQuery Validate is loaded
//       if (typeof $.fn.validate !== 'function') {
//         console.error('jQuery Validate is not loaded!');
//         return;
//       }

//       let $form = $('#career-apply-form', context);

//       // Prevent double initialization
//       if ($form.data('validated')) return;
//       $form.data('validated', true);

//       // Custom file size validation
//       $.validator.addMethod("filesize", function (value, element, param) {
//         if (element.files.length === 0) return true;
//         return this.optional(element) || (element.files[0].size <= param);
//       }, "File size must be less than 5MB");

//       // Custom file extension validation
//       $.validator.addMethod("extensionFile", function (value, element, param) {
//         if (element.files.length === 0) return true;
//         let allowed = param.split('|');
//         let fileName = element.files[0].name.toLowerCase();
//         for (const ext of allowed) {
//           if (fileName.endsWith(ext)) {
//             return true;
//           }
//         }
//         return false;
//       }, "Invalid file type");

//       // Initialize validation
//       $form.validate({
//         rules: {
//           first_name: { required: true, minlength: 2 },
//           last_name: { required: true, minlength: 2 },
//           email: { required: true, email: true },
//           mobile: { required: true, digits: true, minlength: 10, maxlength: 10 },
//           gender: { required: true },
//           "files[resume]": {
//             required: true,
//             extensionFile: "pdf|doc|docx",
//             filesize: 5242880 // 5MB
//           }
//         },
//         messages: {
//           first_name: { required: "First name is required", minlength: "At least 2 characters" },
//           last_name: { required: "Last name is required", minlength: "At least 2 characters" },
//           email: { required: "Email is required", email: "Enter a valid email address" },
//           mobile: {
//             required: "Mobile number is required",
//             digits: "Only digits allowed",
//             minlength: "Must be 10 digits",
//             maxlength: "Must be 10 digits"
//           },
//           gender: { required: "Please select gender" },
//           "files[resume]": {
//             required: "Please upload your resume",
//             extensionFile: "Allowed: PDF, DOC, DOCX",
//             filesize: "File must be ≤ 5MB"
//           }
//         },
//         errorClass: "text-red-500 text-sm mt-1 block",
//         errorPlacement: function (error, element) {
//           if (element.attr("type") === "file") {
//             error.insertAfter(element); // ← file specific
//           }
//           else if (element.attr("type") === "checkbox" || element.is("select")) {
//             error.insertAfter(element.closest('div'));
//           } else {
//             error.insertAfter(element);
//           }
//         },

//         highlight: function (element) {
//           $(element)
//             .addClass("border-red-500")
//             .closest('.test')
//             .addClass('has-error');
//             $(element).siblings('label').addClass('label-error-fix');
//         },

//         unhighlight: function (element) {
//           $(element)
//             .removeClass("border-red-500")
//             .closest('.test')
//             .removeClass('has-error');
//             $(element).siblings('label').removeClass('label-error-fix');  
//         }
//       });
//       $('#edit-resume-upload', context).rules('add', {
//         requiredFile: true,
//         extensionFile: "pdf|doc|docx",
//         filesize: 5242880,
//         messages: {
//           requiredFile: "Please upload your resume",
//           extensionFile: "Allowed: PDF, DOC, DOCX",
//           filesize: "File must be ≤ 5MB"
//         }
//       });
//     }
//   };
// })(jQuery, Drupal);


(function ($, Drupal) {
  Drupal.behaviors.careerApplyValidation = {
    attach: function (context, settings) {
      if (typeof $.fn.validate !== 'function') return;

      const $form = $('#career-apply-form', context);
      if (!$form.length) return;

      // 1. Initialize Validator
      if (!$form.data('validated')) {
        
        $.validator.addMethod("filesize", function (value, element, param) {
          if (element.files.length === 0) return true;
          return this.optional(element) || (element.files[0].size <= param);
        }, "File size must be less than 5MB");

        $.validator.addMethod("extensionFile", function (value, element, param) {
          if (element.files.length === 0) return true;
          let allowed = param.split('|');
          let fileName = element.files[0].name.toLowerCase();
          return allowed.some(ext => fileName.endsWith(ext));
        }, "Invalid file type");

        $form.validate({
          ignore: [], // Don't ignore the hidden Drupal file inputs
          rules: {
            first_name: { required: true, minlength: 2 },
            last_name: { required: true, minlength: 2 },
            email: { required: true, email: true },
            mobile: { required: true, digits: true, minlength: 10, maxlength: 10 },
            gender: { required: true },
            "files[resume]": {
              required: true,
              extensionFile: "pdf|doc|docx",
              filesize: 5242880
            }
          },
          messages: {
            "files[resume]": {
              required: "Please upload your resume",
              extensionFile: "Allowed: PDF, DOC, DOCX",
              filesize: "File must be ≤ 5MB"
            }
          },
          errorClass: "text-red-500 text-sm mt-1 block",
          errorPlacement: function (error, element) {
            if (element.attr("name") === "files[resume]") {
              error.appendTo(element.closest('.js-form-managed-file'));
            } else if (element.attr("type") === "checkbox" || element.is("select")) {
              error.insertAfter(element.closest('.js-form-item'));
            } else {
              error.insertAfter(element);
            }
          },
          highlight: function (element) {
            const $el = $(element);
            $el.addClass("border-red-500").closest('.test').addClass('has-error');
            $el.closest('.js-form-item').addClass('has-error');

            let $label = $el.siblings('label');
            if (!$label.length) {
                $label = $el.closest('.js-form-item').find('label');
            }
            $label.addClass('label-error-fix').removeClass('peer-placeholder-shown:-translate-y-1/2');
          },
          unhighlight: function (element) {
            const $el = $(element);
            $el.removeClass("border-red-500").closest('.test').removeClass('has-error');
            $el.closest('.js-form-item').removeClass('has-error');

            let $label = $el.siblings('label');
            if (!$label.length) {
                $label = $el.closest('.js-form-item').find('label');
            }
            $label.removeClass('label-error-fix').addClass('peer-placeholder-shown:-translate-y-1/2');
          }
        });

        $form.data('validated', true);
      }

      // 2. Refresh rules for the file input (handles AJAX replacement)
      const $fileInput = $('input[name="files[resume]"]', $form);
      if ($fileInput.length) {
        $fileInput.rules('add', {
          required: true,
          extensionFile: "pdf|doc|docx",
          filesize: 5242880
        });
      }

      // 3. Manual trigger on button without using .once()
      // We .off() first to prevent duplicate event listeners
      $('#edit-submit', $form).off('click.validationFix').on('click.validationFix', function (e) {
        if (!$form.valid()) {
          e.preventDefault();
          e.stopImmediatePropagation();
          
          const $firstError = $(".text-red-500").first();
          if ($firstError.length) {
            $('html, body').animate({
              scrollTop: ($firstError.offset().top - 100)
            }, 500);
          }
          return false;
        }
      });
    }
  };
})(jQuery, Drupal);