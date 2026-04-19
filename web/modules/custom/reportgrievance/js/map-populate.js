(function ($, Drupal, drupalSettings) {
  'use strict';

  /* ---------------- CONSTANTS ---------------- */

  const MAP_TARGET = 'mapDiv';
  const INCIDENT_LAYER = 'Incident_Layer';
  const DEFAULT_ZOOM = 15;
  const MAP_DELAY_MS = 2500;
  const MARKER_ICON = './themes/custom/engage_theme/images/user-location-50.png';

  let gmap = null;

  /* ---------------- BOOTSTRAP ---------------- */

  initializeEnvSettings();
  window.onload = createMap;

  $(document).on('click', '.get_lat_lang', () => {
    if (gmap) {
      addPoint(gmap);
    }
  });

  /* ---------------- ENV SETTINGS ---------------- */

  function initializeEnvSettings() {
    const config = drupalSettings.globalVariables?.mapConfig?.[0];
    if (!config) {
      console.warn('Map configuration missing');
      return;
    }

    applyEnvSettings(config);
  }

  function applyEnvSettings(config) {
    Object.assign(envSettings, {
      gKey: config.gKey,
      mapDimension: config.mapDimension,
      mapLib: config.mapLib,
      type: config.type,
      mapData: config.mapData,
      gwc: config.gwc,
      offline: config.offline,
      extent1: Number.parseFloat(config.extent1),
      extent2: Number.parseFloat(config.extent2),
      extent3: Number.parseFloat(config.extent3),
      extent4: Number.parseFloat(config.extent4),
      lat: Number.parseFloat(config.lat),
      lon: Number.parseFloat(config.lon),
    });
  }

  /* ---------------- MAP CREATION ---------------- */

  function createMap() {
    tmpl.Map.mapCreation({
      target: MAP_TARGET,
      callBackFun: onMapReady,
    });
  }

  function onMapReady(mapInstance, response) {
    gmap = mapInstance;

    setTimeout(initializeMapUI, MAP_DELAY_MS);
    console.debug('Map initialized', response);
  }

  function initializeMapUI() {
    addSearchBox();
    zoomToExtent(gmap);
  }

  /* ---------------- SEARCH ---------------- */

  function addSearchBox() {
    tmpl.Search.addSearchBox({
      map: gmap,
      img_url: MARKER_ICON,
      height: 50,
      width: 25,
      zoom_button: false,
    });
  }

  /* ---------------- ZOOM ---------------- */

  function zoomToExtent(map) {
    if (!map) return;

    safeExecute(() =>
      tmpl.Zoom.toExtent({
        map,
        extent: getExtent(),
      })
    );
  }

  function zoomToPoint(coord) {
    tmpl.Zoom.toXYcustomZoom({
      map: gmap,
      latitude: coord[1],
      longitude: coord[0],
      zoom: DEFAULT_ZOOM,
    });
  }

  function getExtent() {
    return [
      envSettings.extent1,
      envSettings.extent2,
      envSettings.extent3,
      envSettings.extent4,
    ];
  }

  /* ---------------- DRAW POINT ---------------- */

  function addPoint(map) {
    tmpl.Draw.draw({
      map,
      type: 'Point',
      callbackFunc: handlePointDrawn,
    });
  }

  function handlePointDrawn(coord) {
    if (!isValidCoord(coord)) return;

    resetIncidentLayer();
    renderIncident(coord);
    zoomToPoint(coord);
    geocodePoint(coord);
    updateLatLngInputs(coord);
  }

  function isValidCoord(coord) {
    return Array.isArray(coord) && coord.length >= 2;
  }

  /* ---------------- INCIDENT MARKER ---------------- */

  function resetIncidentLayer() {
    tmpl.Layer.clearData({
      map: gmap,
      layer: INCIDENT_LAYER,
    });
  }

  function renderIncident(coord) {
    tmpl.Overlay.create({
      map: gmap,
      features: [createMarker(coord)],
      layer: INCIDENT_LAYER,
      layerSwitcher: false,
    });

    tmpl.Layer.changeVisibility({
      map: gmap,
      visible: true,
      layer: INCIDENT_LAYER,
    });
  }

  function createMarker(coord) {
    return {
      id: 1,
      img_url: MARKER_ICON,
      lat: coord[1],
      lon: coord[0],
    };
  }

  /* ---------------- GEOCODING ---------------- */

  function geocodePoint(coord) {
    tmpl.Geocode.getGeocode({
      point: coord,
      callbackFunc: handleGeocode,
    });
  }

  function handleGeocode(data) {
    const address = data?.address;
    if (!address) return;

    sessionStorage.setItem('grievanceAddress', address);
    setInputValue('#edit-address', address);
  }

  /* ---------------- FORM HELPERS ---------------- */

  function updateLatLngInputs(coord) {
    setInputValue('.lat-input', coord[0]);
    setInputValue('.lng-input', coord[1]);
  }

  function setInputValue(selector, value) {
    const input = document.querySelector(selector);
    if (input) {
      input.value = value;
      input.setAttribute('value', value);
    }
  }

  /* ---------------- UTIL ---------------- */

  function safeExecute(fn) {
    try {
      fn();
    } catch (error) {
      console.error('Map operation failed', error);
    }
  }

})(jQuery, Drupal, drupalSettings);