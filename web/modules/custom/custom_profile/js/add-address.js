(function ($, Drupal) {
    console.log("add-address");

    /**
 * `createMap()` function is used to create a map object
 */

    let gmap = null;
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

    function createMap() {
        try {
            map = tmpl.Map.mapCreation({
                target: "mapDiv",
                callBackFun: function callBackFun(maps, res) {
                    gmap = maps;
                    setTimeout(() => {
                        addSearchBox();
                        zoomToExtent(maps);
                    }, 2500);
                    console.log("Responce..MapObject & Responce Object", maps, res);
                },
            });
        } catch (error) {
            console.error("Error at map creation:");
            throw error;
        }
        console.log("Map Creation : ", map);
    }

    function addSearchBox() {
        let search = tmpl.Search.addSearchBox({
            map: gmap,
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

    $(document).on("click", ".get_lat_lang", function () {
        addPoint(gmap);
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
        try {
            tmpl.Draw.draw({
                map: mapData,
                type: "Point",
                callbackFunc: getDrawFeatureDetails,
            });
        } catch (error) {
            console.error("Error at Addpoint:", error);
        }
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
            map: gmap,
            layer: "Incident_Layer",
        });
        tmpl.Overlay.create({
            map: gmap,
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
            map: gmap,
            visible: true,
            layer: "Incident_Layer",
        });
        tmpl.Zoom.toXYcustomZoom({
            map: gmap,
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
        }
    }

    /**
 * It takes the address from the geocode API and sets it as the value of the address input field
 * @param data - The data object returned from the geocoder.
 */
    function handleGeocode(data) {
        console.log(data);
        let split_data = data.address.split(",");
        console.log("Split Data", split_data);
        document.querySelector("#edit-flat").value = split_data[0];
        document.querySelector("#edit-area").value = split_data[1];
        if (split_data.length > 4) {
            document.querySelector("#edit-landmark").value = split_data[2];
            document.querySelector("#edit-country").value = split_data[split_data.length - 1];
        } else {
            document.querySelector("#edit-country").value =
                split_data[split_data.length - 1];
        }
    }

    /* Waiting for the page to load before running the code. */
    window.onload = function () {
        createMap();
    };
})(jQuery, Drupal);

(function ($, Drupal) {
  Drupal.behaviors.addAddressValidation = {
    attach: function (context, settings) {
      // Ensure jQuery Validate is loaded
      if (typeof $.fn.validate !== 'function') {
        console.error('jQuery Validate is not loaded!');
        return;
      }

      let $form = $('#add-address-form', context);
      console.log("Form",$form);
      // Prevent double initialization
      if ($form.data('validated')) return;
      $form.data('validated', true);

      // Initialize validation
      $form.validate({
        rules: {
          postal_code: { required: true, digits: true, minlength: 6, maxlength: 6 },
          flat: { required: true },
          area: { required: true },
          landmark: { required: true },
          country: { required: true },
          address_type: { required: true }
        },
        messages: {
          postal_code: { 
            required: "Postal code is required", 
            digits: "Only digits allowed", 
            minlength: "Postal code must be 6 digits", 
            maxlength: "Postal code must be 6 digits"
          },
          flat: { required: "Flat/House no. is required" },
          area: { required: "Area is required" },
          landmark: { required: "Landmark is required" },
          country: { required: "Country is required" },
          address_type: { required: "Please select an address type" }
        },
        errorClass: "text-red-500 text-sm mt-1 block",
        errorPlacement: function (error, element) {
          error.insertAfter(element);
        },
        highlight: function (element) { $(element).addClass("border-red-500"); },
        unhighlight: function (element) { $(element).removeClass("border-red-500"); }
      });
    }
  };
})(jQuery, Drupal);