/* ======================================================
 * IDEAS SUCCESS POPUP
 * ====================================================== */
(function (Drupal, drupalSettings) {
  Drupal.behaviors.ideasPopup = {
    attach(context, settings) {
      if (!shouldShowPopup(context, settings)) {
        return;
      }

      settings.ideas.submissionSuccess = false;

      const overlay = createPopupOverlay();
      document.body.appendChild(overlay);

      bindPopupClose(overlay);
    }
  };

  function shouldShowPopup(context, settings) {
    return (
      settings.ideas?.submissionSuccess &&
      !context.querySelector('.ideas-popup-modal')
    );
  }

  function createPopupOverlay() {
    const overlay = document.createElement('div');
    overlay.className =
      'ideas-popup-modal fixed inset-0 flex items-center justify-center bg-black bg-opacity-50 z-50';

    overlay.innerHTML = `
      <div class="bg-white rounded-lg shadow-lg p-10 text-center flex flex-col items-center">
        <img src="themes/custom/engage_theme/images/Profile/success.png" alt="Success Popup">
        <p class="font-bold text-3xl font-['nevis'] mb-5">
          Your idea has been submitted successfully.
        </p>
        <button id="popup-close"
          class="mt-2 bg-yellow-500 hover:bg-yellow-600 text-white py-2 px-4 rounded">
          Close
        </button>
      </div>
    `;
    return overlay;
  }

  function bindPopupClose(overlay) {
    const closeBtn = overlay.querySelector('#popup-close');
    if (!closeBtn) return;

    closeBtn.addEventListener('click', () => {
      overlay.classList.add('fade-out');
      setTimeout(() => overlay.remove(), 300);
    });
  }
})(Drupal, drupalSettings);

/* ======================================================
 * IDEAS FORM VALIDATION
 * ====================================================== */
(function ($, Drupal) {
  Drupal.behaviors.ideasFormValidation = {
    attach(context) {
      if (!isValidatorAvailable()) {
        return;
      }

      const $form = $('#ideas-form', context);
      if (isAlreadyValidated($form)) {
        return;
      }

      registerValidationMethods();
      initializeValidation($form);
      overrideDrupalAjaxValidation();
    }
  };

  function isValidatorAvailable() {
    if ($.validator === undefined) {
      console.error('jQuery Validate is not loaded!');
      return false;
    }
    return true;
  }

  function isAlreadyValidated($form) {
    if ($form.data('validated')) {
      return true;
    }
    $form.data('validated', true);
    return false;
  }

  function registerValidationMethods() {
    $.validator.addMethod(
      'filesize',
      (value, element, maxSize) =>
        element.files.length === 0 ||
        element.files[0].size <= maxSize,
      'File size must be less than 2MB'
    );

    $.validator.addMethod(
      'extensionFile',
      (value, element, allowedExts) =>
        element.files.length === 0 ||
        allowedExts.split('|').some(ext =>
          element.files[0].name.toLowerCase().endsWith(ext)
        ),
      'Invalid file type'
    );
  }

  function initializeValidation($form) {
    $form.validate({
      rules: {
        first_name: { required: true, minlength: 2, maxlength: 50 },
        author: { required: true },
        category_idea: { required: true },
        idea_content: { required: true, minlength: 5 },
        'files[upload_file]': {
          required: true,
          extensionFile: 'jpg|jpeg|png|pdf',
          filesize: 2097152
        },
        terms: { required: true }
      },
      messages: {
        first_name: {
          required: 'Title is required',
          minlength: 'Title must be at least 2 characters',
          maxlength: 'Max 50 characters'
        },
        author: 'Author is required',
        category_idea: 'Please select a category',
        idea_content: {
          required: 'Idea content is required',
          minlength: 'Idea content must be at least 5 characters'
        },
        'files[upload_file]': {
          required: 'Please upload a file',
          extensionFile: 'Invalid file type',
          filesize: 'File must be <= 2MB'
        },
        terms: 'You must agree to the Terms and Conditions'
      },
      errorClass: 'text-red-500 text-sm mt-1 block',
      errorPlacement(error, element) {
        element.attr('type') === 'checkbox'
          ? error.insertAfter(element.closest('div'))
          : error.insertAfter(element);
      },
      highlight: el => $(el).addClass('border-red-500'),
      unhighlight: el => $(el).removeClass('border-red-500')
    });
  }

  function overrideDrupalAjaxValidation() {
    if (Drupal.Ajax === undefined) {
      return;
    }

    const originalBeforeSubmit = Drupal.Ajax.prototype.beforeSubmit;

    Drupal.Ajax.prototype.beforeSubmit = function (...args) {
      const $form = this.$form;

      if (
        $form &&
        !$form.hasClass('ajax-submit-prevented') &&
        $(this.element).attr('formnovalidate') === undefined
      ) {
        if (!$form.valid()) {
          this.ajaxing = false;
          $form.addClass('ajax-submit-prevented');
          return false;
        }
      }

      return originalBeforeSubmit.apply(this, args);
    };
  }
})(jQuery, Drupal);

/* ======================================================
 * IDEAS FILE PRE-UPLOAD
 * ====================================================== */
(function ($, Drupal) {
  Drupal.behaviors.ideasFilePreUpload = {
    attach(context) {
      const $fileInput = $('#edit-upload-file', context);
      const $hiddenField = $('#uploaded_file_url', context);

      if (isAlreadyBound($fileInput)) {
        return;
      }

      const $status = createStatusElement($fileInput);
      bindFileUpload($fileInput, $hiddenField, $status);
    }
  };

  function isAlreadyBound($el) {
    if ($el.data('ideas-file-uploaded')) {
      return true;
    }
    $el.data('ideas-file-uploaded', true);
    return false;
  }

  function createStatusElement($fileInput) {
    const $status = $(
      '<div class="text-sm text-gray-500 mt-1 hidden">Uploading...</div>'
    );
    $fileInput.after($status);
    return $status;
  }

  function bindFileUpload($fileInput, $hiddenField, $status) {
    $fileInput.on('change', function () {
      const file = this.files[0];
      if (!file) return;

      uploadFile(file, $hiddenField, $status);
    });
  }

  function uploadFile(file, $hiddenField, $status) {
    $status.text('Uploading...').removeClass('hidden');

    const formData = new FormData();
    formData.append('files[upload_file]', file);

    $.ajax({
      url: '/ideas/upload-file',
      type: 'POST',
      data: formData,
      contentType: false,
      processData: false
    })
      .done(response => handleUploadSuccess(response, $hiddenField, $status))
      .fail(() => handleUploadFailure($hiddenField, $status));
  }

  function handleUploadSuccess(response, $hiddenField, $status) {
    if (!response?.fileUrl) {
      handleUploadFailure($hiddenField, $status);
      return;
    }

    $hiddenField.val(response.fileUrl).attr('data-uploaded', 'true');
    $status.text('File uploaded successfully');
  }

  function handleUploadFailure($hiddenField, $status) {
    $hiddenField.val('').attr('data-uploaded', 'false');
    $status.text('Upload failed');
  }
})(jQuery, Drupal);