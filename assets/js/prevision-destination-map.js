import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

L.Icon.Default.mergeOptions({
  iconRetinaUrl: markerIcon2x,
  iconUrl: markerIcon,
  shadowUrl: markerShadow,
});

const coordinateValue = (value, min, max) => {
  if (value === null || value === undefined || value === '') {
    return null;
  }

  const number = Number.parseFloat(String(value).replace(',', '.'));

  return Number.isFinite(number) && number >= min && number <= max ? number : null;
};

const formatCoordinate = (value) => value.toFixed(7);

const dispatchFieldEvents = (field) => {
  field?.dispatchEvent(new Event('input', { bubbles: true }));
  field?.dispatchEvent(new Event('change', { bubbles: true }));
};

const assign = (field, value, dispatch = true) => {
  if (!field || value === null || value === undefined) {
    return;
  }

  field.value = String(value);

  if (dispatch) {
    dispatchFieldEvents(field);
  }
};

const enableLink = (link, href) => {
  if (!link) {
    return;
  }

  link.href = href;
  link.removeAttribute('aria-disabled');
};

const updateCoordinateLinks = (fields, links) => {
  const latitude = coordinateValue(fields.latitude?.value, -90, 90);
  const longitude = coordinateValue(fields.longitude?.value, -180, 180);

  if (latitude === null || longitude === null) {
    links.maps?.setAttribute('aria-disabled', 'true');
    links.osm?.setAttribute('aria-disabled', 'true');
    links.container?.setAttribute('hidden', '');
    return;
  }

  const lat = formatCoordinate(latitude);
  const lng = formatCoordinate(longitude);
  enableLink(links.maps, `https://www.google.com/maps?q=${lat},${lng}`);
  enableLink(links.osm, `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=16/${lat}/${lng}`);
  links.container?.removeAttribute('hidden');
};

const setStatus = (element, message) => {
  if (element) {
    element.textContent = message;
  }
};

const selectSupportsValue = (field, value) => {
  if (!field || !('options' in field)) {
    return false;
  }

  return Array.from(field.options).some((option) => option.value === value);
};

const initPrevisionDestinationMap = (root) => {
  const mapElement = root.querySelector('[data-prevision-map]');

  if (!mapElement) {
    return;
  }

  const fields = {
    source: root.querySelector('[data-prevision-source]'),
    country: root.querySelector('[data-prevision-country]'),
    region: root.querySelector('[data-prevision-region]'),
    department: root.querySelector('[data-prevision-department]'),
    commune: root.querySelector('[data-prevision-commune]'),
    insee: root.querySelector('[data-prevision-insee]'),
    postal: root.querySelector('[data-prevision-postal]'),
    latitude: root.querySelector('[data-prevision-latitude]'),
    longitude: root.querySelector('[data-prevision-longitude]'),
    accuracy: root.querySelector('[data-prevision-accuracy]'),
    communeCenterLatitude: root.querySelector('[data-prevision-commune-center-latitude]'),
    communeCenterLongitude: root.querySelector('[data-prevision-commune-center-longitude]'),
  };

  const links = {
    container: root.querySelector('[data-prevision-map-links]'),
    maps: root.querySelector('[data-prevision-maps-link]'),
    osm: root.querySelector('[data-prevision-osm-link]'),
  };

  const mapPanel = root.querySelector('[data-prevision-map-panel]');
  const mapPlaceholder = root.querySelector('[data-prevision-map-placeholder]');
  const mapStatus = root.querySelector('[data-prevision-map-status]');
  const gpsStatus = root.querySelector('[data-prevision-gps-status]');
  const validateButton = root.querySelector('[data-prevision-validate-point]');
  const centerButton = root.querySelector('[data-prevision-center-commune]');
  const gpsButton = root.querySelector('[data-prevision-gps]');
  const searchInput = root.querySelector('[data-prevision-search]');
  const searchStatus = root.querySelector('[data-prevision-search-status]');
  const searchResults = root.querySelector('[data-prevision-search-results]');

  const defaultLatitude = coordinateValue(mapElement.dataset.defaultLatitude, -90, 90) ?? 42.6;
  const defaultLongitude = coordinateValue(mapElement.dataset.defaultLongitude, -180, 180) ?? 2.6;
  const defaultZoom = Number.parseInt(mapElement.dataset.defaultZoom ?? '9', 10) || 9;

  let markerPosition = null;
  let pendingSource = 'manual_map';
  let searchAbortController = null;
  let map = null;
  let marker = null;

  const existingLatitude = coordinateValue(fields.latitude?.value, -90, 90);
  const existingLongitude = coordinateValue(fields.longitude?.value, -180, 180);
  const centerLatitude = coordinateValue(fields.communeCenterLatitude?.value, -90, 90);
  const centerLongitude = coordinateValue(fields.communeCenterLongitude?.value, -180, 180);

  const setPendingMapPoint = (latlng, message) => {
    pendingSource = 'manual_map';
    moveMarker(latlng.lat, latlng.lng);
    setStatus(mapStatus, message);
  };

  const hasCommuneCenter = () => (
    coordinateValue(fields.communeCenterLatitude?.value, -90, 90) !== null
    && coordinateValue(fields.communeCenterLongitude?.value, -180, 180) !== null
  );

  const setMapControls = () => {
    const hasMapPoint = markerPosition !== null;
    if (validateButton) {
      validateButton.disabled = !hasMapPoint;
    }
    if (centerButton) {
      centerButton.disabled = !hasCommuneCenter();
    }
  };

  const createMap = (latitude, longitude, zoom) => {
    const initialPosition = [latitude, longitude];
    map = L.map(mapElement, {
      center: initialPosition,
      zoom,
      scrollWheelZoom: true,
    });

    map.scrollWheelZoom.enable();

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    marker = L.marker(initialPosition, {
      draggable: true,
      title: 'Point précis à valider',
    }).addTo(map);

    marker.on('dragend', () => {
      markerPosition = marker.getLatLng();
      pendingSource = 'manual_map';
      setMapControls();
      setStatus(mapStatus, 'Point déplacé. Cliquez sur Valider ce point pour enregistrer les coordonnées.');
    });

    map.on('click', (event) => {
      setPendingMapPoint(event.latlng, 'Point déplacé. Cliquez sur Valider ce point pour enregistrer les coordonnées.');
    });
  };

  const showMap = (latitude, longitude, zoom = 14) => {
    mapPanel?.removeAttribute('hidden');
    mapPlaceholder?.setAttribute('hidden', '');

    if (!map || !marker) {
      createMap(latitude, longitude, zoom);
    }

    moveMarker(latitude, longitude, zoom);
    setMapControls();
    setTimeout(() => map?.invalidateSize(), 0);
  };

  const moveMarker = (latitude, longitude, zoom = null) => {
    if (!map || !marker) {
      showMap(latitude, longitude, zoom ?? 14);
      return;
    }

    markerPosition = L.latLng(latitude, longitude);
    marker.setLatLng(markerPosition);

    if (zoom !== null) {
      map.setView(markerPosition, zoom);
    } else {
      map.panTo(markerPosition);
    }

    setMapControls();
  };

  if (existingLatitude !== null && existingLongitude !== null) {
    showMap(existingLatitude, existingLongitude, 14);
  } else {
    mapPanel?.setAttribute('hidden', '');
    mapPlaceholder?.removeAttribute('hidden');
    markerPosition = null;
    setMapControls();
  }

  updateCoordinateLinks(fields, links);

  validateButton?.addEventListener('click', () => {
    if (!markerPosition) {
      setStatus(mapStatus, 'Choisissez un point sur la carte avant de valider.');
      return;
    }

    assign(fields.latitude, formatCoordinate(markerPosition.lat));
    assign(fields.longitude, formatCoordinate(markerPosition.lng));

    if (pendingSource === 'gps') {
      assign(fields.source, 'gps');
    } else {
      assign(fields.source, selectSupportsValue(fields.source, 'manual_map') ? 'manual_map' : 'manual');
      assign(fields.accuracy, '');
    }

    updateCoordinateLinks(fields, links);
    setStatus(mapStatus, 'Point validé sur la carte.');
  });

  centerButton?.addEventListener('click', () => {
    const latitude = coordinateValue(fields.communeCenterLatitude?.value, -90, 90);
    const longitude = coordinateValue(fields.communeCenterLongitude?.value, -180, 180);

    if (latitude === null || longitude === null) {
      setStatus(mapStatus, 'Sélectionnez une commune avant de centrer la carte.');
      return;
    }

    pendingSource = 'manual_map';
    moveMarker(latitude, longitude, 14);
    setStatus(mapStatus, 'Carte recentrée sur la commune. Cliquez sur “Valider ce point” pour utiliser ce point.');
  });

  gpsButton?.addEventListener('click', () => {
    if (!navigator.geolocation) {
      setStatus(gpsStatus, 'La géolocalisation n’est pas disponible sur ce navigateur.');
      return;
    }

    setStatus(gpsStatus, 'Recherche de position en cours...');
    navigator.geolocation.getCurrentPosition((position) => {
      const latitude = position.coords.latitude;
      const longitude = position.coords.longitude;
      const accuracy = Number.isFinite(position.coords.accuracy) ? Math.round(position.coords.accuracy) : null;

      pendingSource = 'gps';
      moveMarker(latitude, longitude, 16);

      if (accuracy !== null) {
        assign(fields.accuracy, accuracy);
      }

      setStatus(
        gpsStatus,
        accuracy !== null
          ? `Position GPS trouvée. Précision : ± ${accuracy} m.`
          : 'Position GPS trouvée.',
      );
      setStatus(
        mapStatus,
        accuracy !== null && accuracy > 50
          ? 'Précision faible : ajustez le point sur la carte avant de valider.'
          : 'Position GPS placée sur la carte. Cliquez sur “Valider ce point” pour renseigner les coordonnées.',
      );
    }, () => {
      setStatus(gpsStatus, 'Position GPS indisponible. Vous pouvez déplacer le marqueur ou saisir les coordonnées manuellement.');
    }, {
      enableHighAccuracy: true,
      timeout: 12000,
      maximumAge: 60000,
    });
  });

  fields.latitude?.addEventListener('input', () => updateCoordinateLinks(fields, links));
  fields.longitude?.addEventListener('input', () => updateCoordinateLinks(fields, links));

  searchInput?.addEventListener('input', () => {
    const query = searchInput.value.trim();
    if (searchResults) {
      searchResults.innerHTML = '';
    }

    if (searchAbortController) {
      searchAbortController.abort();
    }

    if (query.length < 3) {
      setStatus(searchStatus, 'Saisissez au moins 3 caractères.');
      return;
    }

    searchAbortController = new AbortController();
    setStatus(searchStatus, 'Recherche en cours...');

    fetch(`https://geo.api.gouv.fr/communes?nom=${encodeURIComponent(query)}&fields=nom,code,codesPostaux,centre,departement,region&boost=population&limit=6`, {
      signal: searchAbortController.signal,
    })
      .then((response) => (response.ok ? response.json() : Promise.reject(new Error('Recherche commune indisponible.'))))
      .then((communes) => {
        if (searchResults) {
          searchResults.innerHTML = '';
        }

        if (!Array.isArray(communes) || communes.length === 0) {
          setStatus(searchStatus, 'Aucune commune trouvée.');
          return;
        }

        setStatus(searchStatus, 'Choisissez une commune pour remplir le classement administratif.');
        communes.forEach((commune) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'prevision-search-result';
          button.textContent = `${commune.nom} - ${commune.departement?.nom ?? 'Département inconnu'} / ${commune.region?.nom ?? 'Région inconnue'}`;
          button.addEventListener('click', () => {
            assign(fields.commune, commune.nom);
            assign(fields.insee, commune.code);
            assign(fields.postal, Array.isArray(commune.codesPostaux) ? commune.codesPostaux[0] : '');
            assign(fields.department, commune.departement?.nom ?? '');
            assign(fields.region, commune.region?.nom ?? '');
            assign(fields.country, 'France');
            assign(fields.source, 'search');

            if (commune.centre?.coordinates?.length === 2) {
              const longitude = coordinateValue(commune.centre.coordinates[0], -180, 180);
              const latitude = coordinateValue(commune.centre.coordinates[1], -90, 90);

              if (latitude !== null && longitude !== null) {
                assign(fields.communeCenterLatitude, formatCoordinate(latitude), false);
                assign(fields.communeCenterLongitude, formatCoordinate(longitude), false);
                pendingSource = 'manual_map';
                showMap(latitude, longitude, 14);
                setStatus(mapStatus, 'Commune sélectionnée. Déplacez le marqueur puis cliquez sur “Valider ce point”.');
              } else {
                setMapControls();
              }
            } else {
              setMapControls();
            }

            setStatus(searchStatus, 'Commune renseignée. Le point sur la carte reste à valider.');
            if (searchResults) {
              searchResults.innerHTML = '';
            }
          });
          searchResults?.append(button);
        });
      })
      .catch((error) => {
        if (error.name === 'AbortError') {
          return;
        }

        setStatus(searchStatus, 'Recherche indisponible. Vous pouvez saisir les informations manuellement.');
      });
  });
};

document.querySelectorAll('[data-prevision-destination-form]').forEach(initPrevisionDestinationMap);
