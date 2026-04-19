/* ---------------- SHARED HELPERS ---------------- */

function resetSelect($select, placeholder, disabled = false) {
  $select
    .empty()
    .append(`<option value="">${placeholder}</option>`)
    .prop('disabled', disabled);
}

async function fetchJson(url) {
  const response = await fetch(url);
  if (!response.ok) {
    throw new Error(`${response.status} ${response.statusText}`);
  }
  return response.json();
}

(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.reportGrievanceForm = {
    attach(context) {
      const $typeSelect = $('.grievance-type-select', context);
      const $subtypeSelect = $('.grievance-subtype-select', context);

      if (!canAttach($typeSelect, $subtypeSelect)) {
        return;
      }

      const endpoints = resolveEndpoints(drupalSettings);

      bindTypeChange($typeSelect, $subtypeSelect, endpoints.subtypes);
      loadTypes($typeSelect, $subtypeSelect, endpoints.types);
    }
  };

  /* ---------------- GUARD ---------------- */

  function canAttach($type, $subtype) {
    if (!$type.length || !$subtype.length || $type.data('attached')) {
      return false;
    }
    $type.data('attached', true);
    return true;
  }

  /* ---------------- CONFIG ---------------- */

  function resolveEndpoints(settings) {
    const cfg = settings?.reportgrievance?.endpoints || {};
    return {
      types: cfg.types || '/grievance/types',
      subtypes: cfg.subtypes || '/grievance/subtypes/'
    };
  }

  /* ---------------- EVENTS ---------------- */

  function bindTypeChange($type, $subtype, subtypesUrl) {
    $type.on('change', () => {
      const value = $type.val();
      value
        ? loadSubtypes($subtype, subtypesUrl, value)
        : resetSelect($subtype, 'Select Sub Category', true);
    });
  }

  /* ---------------- LOADERS ---------------- */

  function loadTypes($type, $subtype, url) {
    resetSelect($type, 'Loading categories...', true);
    resetSelect($subtype, 'Select Sub Category', true);
    loadOptions(url, $type, 'Select a Category');
  }

  function loadSubtypes($subtype, baseUrl, key) {
    resetSelect($subtype, 'Loading subcategories...', true);
    loadOptions(baseUrl + encodeURIComponent(key), $subtype, 'Select Sub Category');
  }

  /* ---------------- SHARED ASYNC ---------------- */

  async function loadOptions(url, $select, placeholder) {
    try {
      const data = await fetchJson(url);
      resetSelect($select, placeholder, false);
      appendOptions($select, data);
    } catch (error) {
      console.error('Failed to load options:', error);
      resetSelect($select, 'Failed to load', true);
    }
  }

  /* ---------------- OPTIONS ---------------- */

  function appendOptions($select, data) {
    if (Array.isArray(data)) {
      for (const item of data) {
        addOption($select, item);
      }
      return;
    }

    if (isObject(data)) {
      for (const [key, value] of Object.entries(data)) {
        $select.append(`<option value="${key}">${value}</option>`);
      }
      return;
    }

    console.error('Unexpected response format:', data);
  }

  function addOption($select, item) {
    const key = item?.key ?? Object.keys(item || {})[0];
    const value = item?.value ?? Object.values(item || {})[0];

    if (key && value) {
      $select.append(`<option value="${key}">${value}</option>`);
    }
  }

  function isObject(value) {
    return typeof value === 'object' && value !== null;
  }

})(jQuery, Drupal, drupalSettings);