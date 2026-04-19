(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.reportGrievanceValidation = {
    attach(context) {
      if (!canAttach(context)) {
        return;
      }

      const $form = $('#report-grievance', context);
      markAttached($form);

      ensureValidatorsRegistered();
      initializeValidation($form);
    }
  };

  /* ---------------- GUARDS ---------------- */

  function canAttach(context) {
    if (!isValidatorAvailable()) {
      console.error('jQuery Validate is not loaded!');
      return false;
    }

    const $form = $('#report-grievance', context);
    return $form.length && !$form.data('validated');
  }

  function markAttached($form) {
    $form.data('validated', true);
  }

  function isValidatorAvailable() {
    return typeof $.fn.validate === 'function';
  }

  /* ---------------- VALIDATORS ---------------- */

  function ensureValidatorsRegistered() {
    if ($.validator.methods.filesize) {
      return;
    }

    registerFileSizeValidator();
    registerExtensionValidator();
  }

  function registerFileSizeValidator() {
    $.validator.addMethod(
      'filesize',
      (_, element, maxSize) =>
        !element.files.length || element.files[0].size <= maxSize,
      'File size must be less than 2MB'
    );
  }

  function registerExtensionValidator() {
    $.validator.addMethod(
      'extensionFile',
      (_, element, allowedExt) =>
        !element.files.length ||
        isAllowedExtension(element.files[0].name, allowedExt),
      'Invalid file type'
    );
  }

  function isAllowedExtension(fileName, allowedExt) {
    const lower = fileName.toLowerCase();
    return allowedExt
      .split('|')
      .some(ext => lower.endsWith(ext));
  }

  /* ---------------- FORM INIT ---------------- */

  function initializeValidation($form) {
    $form.validate({
      rules: getValidationRules(),
      messages: getValidationMessages(),
      errorClass: 'text-red-500 text-sm mt-1 block',
      errorPlacement: placeError,
      highlight: toggleErrorHighlight(true),
      unhighlight: toggleErrorHighlight(false)
    });
  }

  /* ---------------- UI HELPERS ---------------- */

  function placeError(error, element) {
    const target = isCheckbox(element)
      ? element.closest('label')
      : element;
    error.insertAfter(target);
  }

  function isCheckbox(element) {
    return element.attr('type') === 'checkbox';
  }

  function toggleErrorHighlight(add) {
    return element =>
      $(element).toggleClass('border-red-500', add);
  }

  /* ---------------- RULES ---------------- */

  function getValidationRules() {
    return {
      grievance_type: { required: true },
      grievance_subtype: { required: true },
      remarks: { required: true, minlength: 10, maxlength: 255 },
      address: { required: true, maxlength: 255 },
      'files[upload_file]': {
        required: true,
        extensionFile: 'jpg|jpeg|png|pdf|doc|docx|mp4',
        filesize: 2097152
      },
      agree_terms: { required: true }
    };
  }

  function getValidationMessages() {
    return {
      grievance_type: 'Please select a Category',
      grievance_subtype: 'Please select a Sub Category',
      remarks: {
        required: 'Remarks are required',
        minlength: 'At least 10 characters',
        maxlength: 'Max 255 characters'
      },
      address: {
        required: 'Address is required',
        maxlength: 'Max 255 characters'
      },
      'files[upload_file]': {
        required: 'Please upload a file',
        extensionFile: 'Invalid file type',
        filesize: 'File must be <= 2MB'
      },
      agree_terms: 'You must agree to the Terms and Conditions'
    };
  }

})(jQuery, Drupal);