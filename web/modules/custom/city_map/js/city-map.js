(function ($, Drupal) {
    console.log('Report Grievance JS loaded');
    Drupal.city_map = Drupal.city_map || {};
    Drupal.gmap = null;
    let map = null;
    envSettings.gKey = drupalSettings.globalVariables.mapConfig[0].gKey;
    envSettings.mapDimension = drupalSettings.globalVariables.mapConfig[0].mapDimension;
    envSettings.mapLib = drupalSettings.globalVariables.mapConfig[0].mapLib;
    envSettings.type = drupalSettings.globalVariables.mapConfig[0].type;
    envSettings.mapData = drupalSettings.globalVariables.mapConfig[0].mapData;
    envSettings.gwc = drupalSettings.globalVariables.mapConfig[0].gwc;
    envSettings.offline = drupalSettings.globalVariables.mapConfig[0].offline;
    envSettings.extent1 = Number.parseFloat(drupalSettings.globalVariables.mapConfig[0].extent1);
    envSettings.extent2 = Number.parseFloat(drupalSettings.globalVariables.mapConfig[0].extent2);
    envSettings.extent3 = Number.parseFloat(drupalSettings.globalVariables.mapConfig[0].extent3);
    envSettings.extent4 = Number.parseFloat(drupalSettings.globalVariables.mapConfig[0].extent4);
    envSettings.lat = Number.parseFloat(drupalSettings.globalVariables.mapConfig[0].lat);
    envSettings.lon = Number.parseFloat(drupalSettings.globalVariables.mapConfig[0].lon);


    Drupal.city_map.createMap = function () {
        console.log("wqwwew");
        map = tmpl.Map.mapCreation({
            target: "mapDiv",
            callBackFun: function callBackFun(maps, res) {
                Drupal.gmap = maps;
                setTimeout(() => {
                    addSearchBox();
                    zoomToExtent(maps);
                }, 2500);
                console.log("Responce..MapObject & Responce Object", maps, res);
            },
        });
        console.log("Map Creation : ", map);
    }

    function addSearchBox() {
        let search = tmpl.Search.addSearchBox({
            map: Drupal.gmap,
            img_url: "./themes/custom/engage_theme/images/user-location-50.png",
            height: 50,
            width: 25,
            zoom_button: false,
        });
        function result(resule) {
            console.log(result);
            getAddress(resule);
        }
        function getAddress(coord) {
            console.log("coordddd", coord);
            tmpl.Geocode.getGeocode({
                point: [coord.lon, coord.lat],
                callbackFunc: handleGeocode,
            });
        }
        console.log("Add Search..", search);
    }

    /**
     * > The function `mapResize` is called when the window is resized. It calls the `tmpl.Map.resize`
     * function, which is a function that is part of the `tmpl` object
     * @param mapData - The map data object that was returned from the map creation function.
     */
    /**
     * > This function zooms the map to the extent specified in the envSettings object
     * @param mapData - The map object that you want to zoom to the extent of.
     */
    /**
     * It zooms the map to the extent specified in the envSettings object
     * @param mapData - The map object that you want to zoom to the extent of.
     */
    function zoomToExtent(mapData) {
        try {
            tmpl.Zoom.toExtent({
                map: mapData,
                extent: [
                    envSettings.extent1,
                    envSettings.extent2,
                    envSettings.extent3,
                    envSettings.extent4,
                ],
            });
        } catch (error) {
            console.error("Error at tmpl.Zoom.toExtent > ", error);
        }
    }
    Drupal.city_map.removeMap = function(){
    tmpl.Map.remove({ map: Drupal.gmap });
    Drupal.city_map.createMap({
        target : 'mapDiv'
    });
    };
    $(document).on("click", ".get_lat_lang", function () {
        addPoint(Drupal.gmap);
    });

    /**
     * "Draw a point on the map and then call the getDrawFeatureDetails function when the user is done
     * drawing."
     *
     * The first thing we do is call the draw function from the tmpl.Draw object. This function takes an
     * object as a parameter. The object has three properties: map, type, and callbackFunc. The map
     * property is the mapData object that we passed to the addPoint function. The type property is the
     * type of feature we want to draw. In this case, we want to draw a point. The callbackFunc property is
     * the function that we want to call when the user is done drawing. In this case, we want to call the
     * getDrawFeatureDetails function
     * @param mapData - The map object
     */
    function addPoint(mapData) {
        tmpl.Draw.draw({
            map: mapData,
            type: "Point",
            callbackFunc: getDrawFeatureDetails,
        });
    }

    /**
     * The function is called when a user clicks on the map. It takes the coordinates of the click, and
     * uses the tmpl.Geocode.getGeocode function to get the address of the click
     * @param coord - The coordinates of the point that was clicked on the map.
     * @param feature - The feature that was drawn.
     * @param wktGeom - The geometry of the feature in WKT format.
     * @param value - The value of the feature.
     */
    function getDrawFeatureDetails(coord, feature, wktGeom, value) {
        console.log(coord);
        tmpl.Layer.clearData({
            map: Drupal.gmap,
            layer: "Incident_Layer",
        });
        tmpl.Overlay.create({
            map: Drupal.gmap,
            features: [
                {
                    id: 1,
                    label: "",
                    label_color: "#fff",
                    img_url: "./themes/custom/engage_theme/images/user-location-50.png",
                    lat: coord[1],
                    lon: coord[0],
                },
            ],
            layer: "Incident_Layer",
            layerSwitcher: false,
        });
        tmpl.Layer.changeVisibility({
            map: Drupal.gmap,
            visible: true,
            layer: "Incident_Layer",
        });
        tmpl.Zoom.toXYcustomZoom({
            map: Drupal.gmap,
            latitude: coord[1],
            longitude: coord[0],
            zoom: 15,
        });
        getAddress(coord);
        function getAddress(coords) {
            console.log("coordddd", coords);
            tmpl.Geocode.getGeocode({
                point: [coords[0], coords[1]],
                callbackFunc: handleGeocode,
            });

            document.querySelector('.lat-input').value = coord[0];
            document.querySelector('.lng-input').value = coord[1];
        }
    }


    /**
     * It takes the address from the geocode API and sets it as the value of the address input field
     * @param data - The data object returned from the geocoder.
     */
    function handleGeocode(data) {
        let grievanceAddress = sessionStorage.setItem(
            "grievanceAddress",
            data.address
        );
        console.log(data);
        console.log(grievanceAddress);
        let appendAddress = document.querySelector("#edit-address");
        appendAddress.value = data.address;
        appendAddress.setAttribute("value", data.address);
    }

    /* Waiting for the page to load before running the code. */
    window.onload = function () {
        Drupal.city_map.createMap();
    };

})(jQuery, Drupal);

(function ($, Drupal) {

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelector(".googleMap").addEventListener("click", (e) => {
      _googleStreet();
    });

    document.querySelector(".googleSatlite").addEventListener("click", (e) => {
      _googleSatlite();
    });
  });

  function _googleSatlite() {
    let gs = tmpl.Map.switchBaseMaps({
      map: Drupal.gmap,
      id: 3,
    });
    console.log(gs, "googleSatlite");

    document.querySelector('.googleMapDiv').classList.remove('hidden');
    document.querySelector('.googleSatliteDiv').classList.add('hidden');
  }

  function _googleStreet() {
    tmpl.Map.switchBaseMaps({
      map: Drupal.gmap,
      id: 2,
    });
    console.log(Drupal.gmap);
    
    document.querySelector('.googleMapDiv').classList.add('hidden');
    document.querySelector('.googleSatliteDiv').classList.remove('hidden');
  }

})(jQuery, Drupal);
