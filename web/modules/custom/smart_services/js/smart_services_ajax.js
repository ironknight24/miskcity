(function ($, Drupal) {

  function loadSmartService(tid, wrapper) {
    wrapper.addClass('opacity-50 pointer-events-none');

    $.ajax({
      url: '/services/' + tid,
      type: 'GET',
      dataType: 'html',
      success: function (data) {
        replaceSmartServiceContent(data, wrapper);
      },
      error: showSmartServiceError,
      complete: function () {
        wrapper.removeClass('opacity-50 pointer-events-none');
      }
    });
  }

  function replaceSmartServiceContent(data, wrapper) {
    const newContent = $(data).find('#smart-services-wrapper');
    wrapper.replaceWith(newContent);

    // Reattach Drupal behaviors to newly injected content
    const newWrapper = document.getElementById('smart-services-wrapper');
    if (newWrapper) {
      Drupal.attachBehaviors(newWrapper);
    }
  }

  function showSmartServiceError() {
    alert('Failed to load Smart Service. Please try again.');
  }

  function onSmartServiceClick(e) {
    e.preventDefault();

    const $link = $(this);
    const tid = $link.data('tid');
    const wrapper = $('#smart-services-wrapper');

    if (!tid || !wrapper.length) {
      return;
    }

    loadSmartService(tid, wrapper);
  }

  Drupal.behaviors.smartServicesAjax = {
    attach: function (context) {
      $('.ajax-smart-service', context)
        .not('.processed-smart-service')
        .addClass('processed-smart-service')
        .on('click', onSmartServiceClick);
    }
  };
})(jQuery, Drupal);
