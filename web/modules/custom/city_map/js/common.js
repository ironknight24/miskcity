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
    Drupal.city_map?.removeMap();
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
    Drupal.city_map?.removeMap();
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
      setTimeout(() => {  
      addMarker(createMarker(item))
      }, 500);
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
  <div class="border border-gray-300 mb-3 rounded-xl bg-white overflow-hidden">
    <div class="flex flex-row" style="min-height: 120px;">
      <img 
        src="${item.image_url}" 
        alt="${item.title}"
        style="width: 110px; min-width: 110px; object-fit: cover;"
        class="rounded-l-xl"
      >
      <div class="flex flex-col justify-between p-2 flex-1 min-w-0">
      <div>
        <h2 class="text-sm font-bold text-gray-800 leading-5"
            style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
          ${item.title}
        </h2>
        <p class="text-xs text-gray-500 mt-1"
          style="display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden;">
          ${item.address}
        </p>
        <p class="text-xs text-gray-600 mt-1"
          style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
          ${item.description}
        </p>
      </div>
      <div class="flex justify-between items-center mt-1">
        <p class="text-xs text-yellow-600">Timings: ${item.timings}</p>
        <p class="text-xs font-bold text-green-600">₹ ${item.price}</p>
      </div>
    </div>
    </div>
  </div>
</div>
        `;
  }

  function renderPOIDetails(item) {
    if (!item) {
      return;
    }
    Drupal.city_map?.removeMap();
    setTimeout(() => {  
      addMarker(createMarker(item))
      }, 500);  // single marker
    zoomToLocation(item);
    
    const container = document.querySelector('.poi-cards');
    container.innerHTML = `
      <button class="mt-6 bg-gray-200 px-4 py-2 rounded back-to-list">← Back to List</button>
      <div class="poi-full-details bg-white rounded-xl p-4">
        <img src="${item.image_url}" alt="${item.title}" class="w-full h-64 object-cover rounded-xl mb-4">
        <h2 class="text-xl font-bold mb-2">${item.title}</h2>
        <p class="text-sm mb-4">${item.description}</p>
        <div class="bg-gray-200 my-5 mx-1 h-px"></div>
        <div class="py-2 px-2">
        <a target="_blank" href="https://www.google.com/maps/dir/?api=1&origin=My+Location&destination=${item.latitude},${item.longitude}">
          <img src="themes/custom/engage_theme/images/CityMap/direction.svg" class="w-14 h-12 cursor-pointer" alt="Pointer">
        <p>Directions</p>
        </a>

        </div>
        <div class="bg-gray-200 my-5 mx-1 h-px"></div>
        <div class="flex gap-5 mb-3 items-center">
          <img src="themes/custom/engage_theme/images/CityMap/location.svg" class="w-4 h-5" alt="Pointer">
          <p class="text-xs">${item.address}</p>
        </div>
        <div class="flex gap-5 mb-3 items-center">
          <img src="themes/custom/engage_theme/images/CityMap/clock.svg" class="w-4 h-5" alt="Pointer">
          <p class="text-xs">${item.timings}</p>
        </div>
         <div class="flex gap-5 mb-3 items-center">
          <img src="themes/custom/engage_theme/images/CityMap/website.png" class="w-4 h-5" alt="Pointer">
          <a href="${item.website_url}" target="_blank" class="text-blue-500 underline">Visit Website</a>
        </div>
         <div class="flex gap-5 mb-3 items-center">
          <img src="themes/custom/engage_theme/images/CityMap/phone.svg" class="w-4 h-5" alt="Pointer">
          <p class="text-xs">${item.contact_number}</p>
        </div>
        
      </div>
    `;

    // zoomToLocation(item);
    // // createMarker(item);
    // addMarker(createMarker(item));

    container
      .querySelector('.back-to-list')
      .addEventListener('click', () => {
        console.log('Back to list clicked');
        Drupal.city_map?.removeMap();
        renderPOICards(poiItems);
        
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
      img_url: item.poi_icon || 'themes/custom/engage_theme/images/CityMap/pointer.png',
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
