(function ($, Drupal) {
  'use strict';

  let poiItems = [];

  document.addEventListener('DOMContentLoaded', init);

  function init() {
    bindTermLinks();
    bindSearch();
    bindClose();
  }

  /* -------------------------
   * Term click handling
   * ------------------------- */
  function bindTermLinks() {
    const termLinks = document.querySelectorAll('.term-link');

    for (const link of termLinks) {
      link.addEventListener('click', (e) => {
        e.preventDefault();
        handleTermClick(link);
      });
    }
  }

  function handleTermClick(link) {
    const linkText =
      link.querySelector('.linktoDet')?.textContent.trim() || '';

    updateSearchPlaceholder(linkText);
    showPOIList();
    showLoader();

    const termId = link.dataset.tid;
    if (!termId) {
      return;
    }

    fetchPOIData(termId);
  }

  function fetchPOIData(termId) {
    fetch(`/api/get-content-by-term/${termId}`)
      .then((response) => response.json())
      .then(handlePOIResponse)
      .catch(showError);
  }

  function handlePOIResponse(data) {
    if (!data?.count) {
      showEmptyMessage();
      return;
    }

    poiItems = data.items;
    renderPOICards(poiItems);
  }

  /* -------------------------
   * Search
   * ------------------------- */
  function bindSearch() {
    const input = document.querySelector('#searchkey');
    if (!input) {
      return;
    }

    input.addEventListener('input', (e) => {
      const keyword = e.target.value.toLowerCase();
      const filtered = poiItems.filter((item) =>
        item.title.toLowerCase().includes(keyword)
      );
      renderPOICards(filtered);
    });
  }

  /* -------------------------
   * Close POI list
   * ------------------------- */
  function bindClose() {
    document.querySelector('.poi_close')?.addEventListener('click', () => {
      document.querySelector('.pois-list')?.classList.add('hidden');
      Drupal.city_map?.removeMap();
    });
  }

  /* -------------------------
   * Rendering
   * ------------------------- */
  function renderPOICards(items) {
    const container = document.querySelector('.poi-cards');
    if (!container) {
      return;
    }

    container.innerHTML = '';

    if (!items.length) {
      container.innerHTML =
        '<p class="text-center text-gray-500 py-4">No matching results found.</p>';
      return;
    }

    for (const item of items) {
      container.insertAdjacentHTML('beforeend', buildPOICard(item));
    }

    const poiCards = container.querySelectorAll('.poiDetl');
    for (const card of poiCards) {
      attachPOICardHandler(card);
    }

    function attachPOICardHandler(card) {
      card.addEventListener('click', () => handlePOICardClick(card));
    }

    function handlePOICardClick(card) {
      const item = findPOIItem(card.dataset.poiid);
      if (!item) {
        return;
      }

      renderPOIDetails(item);
    }

    function findPOIItem(poiId) {
      return items.find((item) => item.id == poiId);
    }

  }

  function buildPOICard(item) {
    return `
      <div class="lists poiDetl cursor-pointer" data-poiid="${item.id}">
        <div class="grid card card-side border border-gray-300 mb-6 rounded-xl bg-white">
          <img src="${item.image_url}" alt="${item.title}" class="w-full h-48 object-cover rounded-xl">
          <div class="p-4">
            <h2 class="font-bold text-lg">${item.title}</h2>
            <p class="text-sm text-gray-500">${item.address}</p>
            <p class="text-sm">${item.description}</p>
          </div>
        </div>
      </div>`;
  }

  function renderPOIDetails(item) {
    if (!item) {
      return;
    }

    const container = document.querySelector('.poi-cards');
    container.innerHTML = `
      <button class="back-to-list mb-4">← Back</button>
      <h2 class="text-xl font-bold">${item.title}</h2>
      <p>${item.description}</p>
    `;

    zoomToLocation(item);
    addMarker(createMarker(item));

    container
      .querySelector('.back-to-list')
      .addEventListener('click', () => {
        renderPOICards(poiItems);
        Drupal.city_map?.removeMap();
      });
  }

  /* -------------------------
   * Map helpers
   * ------------------------- */
  function zoomToLocation(item) {
    tmpl.Zoom.toXYcustomZoom({
      map: Drupal.gmap,
      latitude: item.latitude,
      longitude: item.longitude,
      zoom: 15,
    });
  }

  function createMarker(item) {
    return {
      id: item.id,
      lat: item.latitude,
      lon: item.longitude,
      label: item.title,
      label_color: '#ba5100',
      img_url: 'themes/custom/engage_theme/images/CityMap/pointer.png',
    };
  }

  function addMarker(marker) {
    tmpl.Overlay.create({
      map: Drupal.gmap,
      features: [marker],
      layer: 'ATMlayer',
      layerSwitcher: false,
    });
  }

  /* -------------------------
   * UI helpers
   * ------------------------- */
  function updateSearchPlaceholder(text) {
    const input = document.querySelector('#searchkey');
    if (input) {
      input.placeholder = `Search ${text}`;
    }
  }

  function showPOIList() {
    document.querySelector('.pois-list')?.classList.remove('hidden');
  }

  function showEmptyMessage() {
    document.querySelector('.poi-cards').innerHTML =
      '<p class="text-center text-gray-500 py-4">No amenities available.</p>';
  }

  function showError() {
    document.querySelector('#content-area').innerHTML =
      '<p>Error loading data.</p>';
  }

  function showLoader() {
    document.querySelector('.poi-cards').innerHTML =
      '<p class="text-center py-4">Loading…</p>';
  }

})(jQuery, Drupal);
