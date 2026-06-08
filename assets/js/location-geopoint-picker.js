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
  if (value === null || value === undefined || String(value).trim() === '') {
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
  if (!field) {
    return;
  }

  field.value = value === null || value === undefined ? '' : String(value);

  if (dispatch) {
    dispatchFieldEvents(field);
  }
};

const text = (element, value) => {
  if (element) {
    element.textContent = value;
  }
};

const selectSupportsValue = (field, value) => {
  if (!field || !('options' in field)) {
    return false;
  }

  return Array.from(field.options).some((option) => option.value === value);
};

const enableLink = (link, href) => {
  if (!link) {
    return;
  }

  link.href = href;
  link.removeAttribute('aria-disabled');
};

const disableLink = (link) => {
  if (!link) {
    return;
  }

  link.href = '#';
  link.setAttribute('aria-disabled', 'true');
};

const initLocationGeopointPicker = (root) => {
  const mapElement = root.querySelector('[data-map-container], [data-prevision-map]');

  if (!mapElement) {
    return;
  }

  const fields = {
    type: root.querySelector('[data-location-type-input]'),
    name: root.querySelector('[data-location-name-input]'),
    area: root.querySelector('[data-location-area-input]'),
    source: root.querySelector('[data-source-input], [data-prevision-source]'),
    country: root.querySelector('[data-country-input], [data-prevision-country]'),
    region: root.querySelector('[data-region-input], [data-prevision-region]'),
    department: root.querySelector('[data-department-input], [data-prevision-department]'),
    departmentCode: root.querySelector('[data-department-code-input]'),
    commune: root.querySelector('[data-commune-input], [data-prevision-commune]'),
    insee: root.querySelector('[data-insee-input], [data-prevision-insee]'),
    postal: root.querySelector('[data-postal-code-input], [data-prevision-postal]'),
    latitude: root.querySelector('[data-latitude-input], [data-prevision-latitude]'),
    longitude: root.querySelector('[data-longitude-input], [data-prevision-longitude]'),
    accuracy: root.querySelector('[data-gps-accuracy-input], [data-prevision-accuracy]'),
    communeCenterLatitude: root.querySelector('[data-commune-center-latitude-input], [data-prevision-commune-center-latitude]'),
    communeCenterLongitude: root.querySelector('[data-commune-center-longitude-input], [data-prevision-commune-center-longitude]'),
  };

  const links = {
    container: root.querySelector('[data-map-links], [data-prevision-map-links]'),
    maps: root.querySelector('[data-map-link], [data-prevision-maps-link]'),
    osm: root.querySelector('[data-osm-link], [data-prevision-osm-link]'),
  };

  const mapPanel = root.querySelector('[data-map-panel], [data-prevision-map-panel]');
  const mapPlaceholder = root.querySelector('[data-map-placeholder], [data-prevision-map-placeholder]');
  const mapStatus = root.querySelector('[data-map-status], [data-prevision-map-status]');
  const gpsStatus = root.querySelector('[data-gps-status], [data-prevision-gps-status]');
  const validateButton = root.querySelector('[data-validate-point], [data-prevision-validate-point]');
  const centerButton = root.querySelector('[data-center-commune], [data-prevision-center-commune]');
  const gpsButton = root.querySelector('[data-location-gps], [data-prevision-gps]');
  const searchInput = root.querySelector('[data-commune-search-input], [data-prevision-search]');
  const searchStatus = root.querySelector('[data-commune-search-status], [data-prevision-search-status]');
  const searchResults = root.querySelector('[data-commune-search-results], [data-prevision-search-results]');
  const searchWrapper = root.querySelector('[data-commune-search-panel]');
  const selectedPanel = root.querySelector('[data-selected-commune-panel], [data-commune-selection]');
  const selectedName = root.querySelector('[data-selected-commune-name], [data-commune-selection-name]');
  const selectedPlace = root.querySelector('[data-selected-commune-place], [data-commune-selection-place]');
  const selectedCode = root.querySelector('[data-selected-commune-code], [data-commune-selection-code]');
  const selectedPostal = root.querySelector('[data-selected-commune-postal], [data-commune-selection-postal]');
  const editCommune = root.querySelector('[data-commune-edit]');

  const defaultLatitude = coordinateValue(mapElement.dataset.defaultLatitude, -90, 90) ?? 42.6;
  const defaultLongitude = coordinateValue(mapElement.dataset.defaultLongitude, -180, 180) ?? 2.6;
  const defaultZoom = Number.parseInt(mapElement.dataset.defaultZoom ?? '9', 10) || 9;

  let markerPosition = null;
  let pendingSource = 'manual_map';
  let searchAbortController = null;
  let requestIndex = 0;
  let map = null;
  let marker = null;

  const hasCommune = () => (fields.commune?.value || '').trim() !== '';

  const hasCommuneCenter = () => (
    coordinateValue(fields.communeCenterLatitude?.value, -90, 90) !== null
    && coordinateValue(fields.communeCenterLongitude?.value, -180, 180) !== null
  );

  const setMapControls = () => {
    if (validateButton) {
      validateButton.disabled = markerPosition === null;
    }
    if (centerButton) {
      centerButton.disabled = !hasCommuneCenter();
    }
  };

  const updateCoordinateLinks = () => {
    const latitude = coordinateValue(fields.latitude?.value, -90, 90);
    const longitude = coordinateValue(fields.longitude?.value, -180, 180);

    if (latitude === null || longitude === null) {
      disableLink(links.maps);
      disableLink(links.osm);
      links.container?.setAttribute('hidden', '');
      return;
    }

    const lat = formatCoordinate(latitude);
    const lng = formatCoordinate(longitude);
    enableLink(links.maps, `https://www.google.com/maps?q=${lat},${lng}`);
    enableLink(links.osm, `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=16/${lat}/${lng}`);
    links.container?.removeAttribute('hidden');
  };

  const renderSelectedCommune = () => {
    const commune = (fields.commune?.value || '').trim();
    const departmentCode = (fields.departmentCode?.value || '').trim();
    const department = (fields.department?.value || '').trim();
    const region = (fields.region?.value || '').trim();
    const code = (fields.insee?.value || '').trim();
    const postal = (fields.postal?.value || '').trim();

    if (!commune) {
      selectedPanel?.setAttribute('hidden', '');
      searchWrapper?.removeAttribute('hidden');
      return;
    }

    text(selectedName, commune);
    text(selectedPlace, [[departmentCode, department].filter(Boolean).join(' '), region].filter(Boolean).join(' - '));
    text(selectedCode, code || '-');
    text(selectedPostal, postal || '-');
    selectedPanel?.removeAttribute('hidden');
    searchWrapper?.setAttribute('hidden', '');
  };

  const moveMarker = (latitude, longitude, zoom = null) => {
    if (!map || !marker) {
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

  const createMap = (latitude, longitude, zoom) => {
    map = L.map(mapElement, {
      center: [latitude, longitude],
      zoom,
      scrollWheelZoom: true,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    marker = L.marker([latitude, longitude], {
      draggable: true,
      title: 'Point précis à valider',
    }).addTo(map);

    marker.on('dragend', () => {
      markerPosition = marker.getLatLng();
      pendingSource = 'manual_map';
      setMapControls();
      text(mapStatus, 'Point déplacé. Cliquez sur Valider ce point pour enregistrer les coordonnées.');
    });

    map.on('click', (event) => {
      pendingSource = 'manual_map';
      moveMarker(event.latlng.lat, event.latlng.lng);
      text(mapStatus, 'Point déplacé. Cliquez sur Valider ce point pour enregistrer les coordonnées.');
    });
  };

  const showMap = (latitude, longitude, zoom = 14) => {
    mapPanel?.removeAttribute('hidden');
    mapPlaceholder?.setAttribute('hidden', '');

    if (!map || !marker) {
      createMap(latitude, longitude, zoom);
    }

    moveMarker(latitude, longitude, zoom);
    window.setTimeout(() => map?.invalidateSize(), 0);
  };

  const hideMap = () => {
    mapPanel?.setAttribute('hidden', '');
    mapPlaceholder?.removeAttribute('hidden');
    markerPosition = null;
    setMapControls();
  };

  const clearValidatedCoordinates = () => {
    assign(fields.latitude, '');
    assign(fields.longitude, '');
    assign(fields.accuracy, '');
    updateCoordinateLinks();
  };

  const communeLabel = (commune) => {
    const departmentCode = commune.departement?.code || '';
    const departmentName = commune.departement?.nom || '';
    const regionName = commune.region?.nom || '';
    const departmentLabel = [departmentCode, departmentName].filter(Boolean).join(' ');

    return [commune.nom || '', departmentLabel, regionName].filter(Boolean).join(' - ');
  };

  const selectCommune = (commune) => {
    const coordinates = Array.isArray(commune.centre?.coordinates) ? commune.centre.coordinates : [];
    const longitude = coordinateValue(coordinates[0], -180, 180);
    const latitude = coordinateValue(coordinates[1], -90, 90);
    const postalCode = Array.isArray(commune.codesPostaux) ? (commune.codesPostaux[0] || '') : '';

    assign(fields.type, 'city');
    assign(fields.name, commune.nom || '');
    assign(fields.area, '');
    assign(fields.commune, commune.nom || '');
    assign(fields.insee, commune.code || '');
    assign(fields.postal, postalCode);
    assign(fields.department, commune.departement?.nom || '');
    assign(fields.departmentCode, commune.departement?.code || '');
    assign(fields.region, commune.region?.nom || '');
    assign(fields.country, 'France');
    assign(fields.source, selectSupportsValue(fields.source, 'search') ? 'search' : fields.source?.value);
    clearValidatedCoordinates();

    if (latitude !== null && longitude !== null) {
      assign(fields.communeCenterLatitude, formatCoordinate(latitude), false);
      assign(fields.communeCenterLongitude, formatCoordinate(longitude), false);
      pendingSource = 'manual_map';
      showMap(latitude, longitude, 14);
      text(mapStatus, 'Commune sélectionnée. Déplacez le marqueur puis cliquez sur Valider ce point.');
    } else {
      assign(fields.communeCenterLatitude, '', false);
      assign(fields.communeCenterLongitude, '', false);
      hideMap();
      text(mapStatus, 'Centre de commune indisponible. Sélectionnez une autre commune ou renseignez les coordonnées.');
    }

    if (searchResults) {
      searchResults.hidden = true;
      searchResults.innerHTML = '';
    }

    text(searchStatus, 'Commune renseignée. Le point GPS reste optionnel tant que vous ne validez pas la carte.');
    renderSelectedCommune();
    setMapControls();
  };

  const renderResults = (communes) => {
    if (!searchResults) {
      return;
    }

    searchResults.innerHTML = '';

    if (!Array.isArray(communes) || communes.length === 0) {
      searchResults.hidden = false;
      const message = document.createElement('p');
      message.className = 'location-picker__result-message quick-destination__result-message prevision-search-result-message';
      message.textContent = 'Aucune commune trouvée.';
      searchResults.append(message);
      return;
    }

    communes.forEach((commune) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'location-picker__result quick-destination__result prevision-search-result';
      button.textContent = communeLabel(commune);
      button.addEventListener('click', () => selectCommune(commune));
      searchResults.append(button);
    });

    searchResults.hidden = false;
  };

  const searchCommunes = async (query) => {
    if (query.length < 3) {
      if (searchResults) {
        searchResults.hidden = true;
        searchResults.innerHTML = '';
      }
      text(searchStatus, query.length === 0 ? '' : 'Saisissez au moins 3 caractères.');
      return;
    }

    requestIndex += 1;
    const currentRequest = requestIndex;

    if (searchAbortController) {
      searchAbortController.abort();
    }
    searchAbortController = new AbortController();
    text(searchStatus, 'Recherche en cours...');

    try {
      const response = await fetch(`https://geo.api.gouv.fr/communes?nom=${encodeURIComponent(query)}&fields=nom,code,codesPostaux,centre,departement,region&boost=population&limit=10`, {
        signal: searchAbortController.signal,
      });

      if (!response.ok) {
        throw new Error('Recherche commune indisponible.');
      }

      const communes = await response.json();
      if (currentRequest !== requestIndex) {
        return;
      }

      text(searchStatus, 'Choisissez une commune dans la liste.');
      renderResults(communes);
    } catch (error) {
      if (error.name === 'AbortError') {
        return;
      }

      if (currentRequest === requestIndex) {
        if (searchResults) {
          searchResults.hidden = true;
          searchResults.innerHTML = '';
        }
        text(searchStatus, 'Recherche indisponible. Vous pouvez réessayer dans quelques instants.');
      }
    }
  };

  validateButton?.addEventListener('click', () => {
    if (!markerPosition) {
      text(mapStatus, 'Choisissez un point sur la carte avant de valider.');
      return;
    }

    assign(fields.latitude, formatCoordinate(markerPosition.lat));
    assign(fields.longitude, formatCoordinate(markerPosition.lng));

    if (pendingSource === 'gps') {
      assign(fields.source, selectSupportsValue(fields.source, 'gps') ? 'gps' : fields.source?.value);
    } else {
      assign(fields.source, selectSupportsValue(fields.source, 'manual_map') ? 'manual_map' : fields.source?.value);
      assign(fields.accuracy, '');
    }

    updateCoordinateLinks();
    text(mapStatus, 'Point validé sur la carte.');
  });

  centerButton?.addEventListener('click', () => {
    const latitude = coordinateValue(fields.communeCenterLatitude?.value, -90, 90);
    const longitude = coordinateValue(fields.communeCenterLongitude?.value, -180, 180);

    if (latitude === null || longitude === null) {
      text(mapStatus, 'Sélectionnez une commune avant de centrer la carte.');
      return;
    }

    pendingSource = 'manual_map';
    showMap(latitude, longitude, 14);
    text(mapStatus, 'Carte recentrée sur la commune. Cliquez sur Valider ce point pour utiliser ce point.');
  });

  gpsButton?.addEventListener('click', () => {
    if (!navigator.geolocation) {
      text(gpsStatus, 'La géolocalisation n’est pas disponible sur ce navigateur.');
      return;
    }

    text(gpsStatus, 'Recherche de position en cours...');
    navigator.geolocation.getCurrentPosition((position) => {
      const latitude = position.coords.latitude;
      const longitude = position.coords.longitude;
      const accuracy = Number.isFinite(position.coords.accuracy) ? Math.round(position.coords.accuracy) : null;

      pendingSource = 'gps';
      showMap(latitude, longitude, 16);

      if (accuracy !== null) {
        assign(fields.accuracy, accuracy);
      }

      text(gpsStatus, accuracy !== null ? `Position GPS trouvée. Précision : +/- ${accuracy} m.` : 'Position GPS trouvée.');
      text(mapStatus, 'Position GPS placée sur la carte. Cliquez sur Valider ce point pour renseigner les coordonnées.');
    }, () => {
      text(gpsStatus, 'Position GPS indisponible. Vous pouvez déplacer le marqueur ou saisir les coordonnées manuellement.');
    }, {
      enableHighAccuracy: true,
      timeout: 12000,
      maximumAge: 60000,
    });
  });

  fields.latitude?.addEventListener('input', updateCoordinateLinks);
  fields.longitude?.addEventListener('input', updateCoordinateLinks);
  searchInput?.addEventListener('input', () => searchCommunes(searchInput.value.trim()));
  editCommune?.addEventListener('click', () => {
    searchWrapper?.removeAttribute('hidden');
    selectedPanel?.setAttribute('hidden', '');
    searchInput?.focus();
    searchInput?.select();
  });

  const existingLatitude = coordinateValue(fields.latitude?.value, -90, 90);
  const existingLongitude = coordinateValue(fields.longitude?.value, -180, 180);
  const centerLatitude = coordinateValue(fields.communeCenterLatitude?.value, -90, 90);
  const centerLongitude = coordinateValue(fields.communeCenterLongitude?.value, -180, 180);

  renderSelectedCommune();

  if (existingLatitude !== null && existingLongitude !== null) {
    showMap(existingLatitude, existingLongitude, 14);
    text(mapStatus, 'Coordonnées déjà renseignées.');
  } else if (hasCommune() && centerLatitude !== null && centerLongitude !== null) {
    showMap(centerLatitude, centerLongitude, defaultZoom || 14);
    text(mapStatus, 'Carte centrée sur la commune. Validez le point uniquement si vous voulez enregistrer des coordonnées précises.');
  } else {
    hideMap();
  }

  updateCoordinateLinks();
  setMapControls();
};

export const initLocationGeopointPickers = () => {
  document.querySelectorAll('[data-location-picker], [data-prevision-destination-form]').forEach(initLocationGeopointPicker);
};

initLocationGeopointPickers();
