const COORDINATE_PATTERN = '[+-]?\\d{1,3}(?:[\\.,]\\d+)?';
const coordinateRegex = new RegExp(COORDINATE_PATTERN, 'g');

const toFloat = (value) => Number.parseFloat(String(value).replace(',', '.'));

const isValidLatitude = (value) => Number.isFinite(value) && value >= -90 && value <= 90;

const isValidLongitude = (value) => Number.isFinite(value) && value >= -180 && value <= 180;

const normalizeCoordinate = (value) => String(Number(value.toFixed(7)));

const buildResult = (latitude, longitude) => {
  const lat = toFloat(latitude);
  const lng = toFloat(longitude);

  if (!isValidLatitude(lat) || !isValidLongitude(lng)) {
    return null;
  }

  return { latitude: lat, longitude: lng };
};

const parseLabeledCoordinates = (value) => {
  const latThenLng = new RegExp(`(?:lat|latitude)\\s*[:=]?\\s*(${COORDINATE_PATTERN})[\\s,;]+(?:lng|lon|longitude)\\s*[:=]?\\s*(${COORDINATE_PATTERN})`, 'i');
  const lngThenLat = new RegExp(`(?:lng|lon|longitude)\\s*[:=]?\\s*(${COORDINATE_PATTERN})[\\s,;]+(?:lat|latitude)\\s*[:=]?\\s*(${COORDINATE_PATTERN})`, 'i');
  const latMatch = value.match(latThenLng);

  if (latMatch) {
    return buildResult(latMatch[1], latMatch[2]);
  }

  const lngMatch = value.match(lngThenLat);

  if (lngMatch) {
    return buildResult(lngMatch[2], lngMatch[1]);
  }

  return null;
};

const parseGoogleMapsUrl = (value) => {
  const atMatch = value.match(new RegExp(`@(${COORDINATE_PATTERN}),\\s*(${COORDINATE_PATTERN})(?:,|/)`, 'i'));

  if (atMatch) {
    return buildResult(atMatch[1], atMatch[2]);
  }

  try {
    const url = new URL(value);
    const queryValue = url.searchParams.get('query') || url.searchParams.get('q') || '';

    if (queryValue !== '') {
      return parseDecimalPair(queryValue);
    }
  } catch {
    const queryMatch = value.match(new RegExp(`[?&](?:query|q)=(${COORDINATE_PATTERN})%2C\\s*(${COORDINATE_PATTERN})`, 'i'))
      || value.match(new RegExp(`[?&](?:query|q)=(${COORDINATE_PATTERN}),\\s*(${COORDINATE_PATTERN})`, 'i'));

    if (queryMatch) {
      return buildResult(queryMatch[1], queryMatch[2]);
    }
  }

  return null;
};

function parseDecimalPair(value) {
  const numbers = Array.from(String(value).matchAll(coordinateRegex), (match) => match[0]);

  for (let index = 0; index < numbers.length - 1; index += 1) {
    const result = buildResult(numbers[index], numbers[index + 1]);

    if (result) {
      return result;
    }
  }

  return null;
}

export function parseGpsCoordinates(value) {
  const text = String(value || '').trim();

  if (text === '') {
    return null;
  }

  return parseLabeledCoordinates(text)
    || parseGoogleMapsUrl(text)
    || parseDecimalPair(text);
}

const setStatus = (statusElement, message) => {
  if (statusElement) {
    statusElement.textContent = message;
  }
};

const updateLinks = (latitudeField, longitudeField, openMapLink, directionsLink, statusElement, validMessage = null) => {
  const result = buildResult(latitudeField?.value, longitudeField?.value);

  if (!result) {
    if (openMapLink) {
      openMapLink.hidden = true;
      openMapLink.removeAttribute('href');
    }

    if (directionsLink) {
      directionsLink.hidden = true;
      directionsLink.removeAttribute('href');
    }

    return false;
  }

  const latitude = normalizeCoordinate(result.latitude);
  const longitude = normalizeCoordinate(result.longitude);
  const query = `${latitude},${longitude}`;

  if (openMapLink) {
    openMapLink.href = `https://www.google.com/maps/search/?api=1&query=${query}`;
    openMapLink.hidden = false;
  }

  if (directionsLink) {
    directionsLink.href = `https://www.google.com/maps/dir/?api=1&destination=${query}`;
    directionsLink.hidden = false;
  }

  if (validMessage) {
    setStatus(statusElement, validMessage);
  }

  return true;
};

const fillCoordinates = (pasteField, latitudeField, longitudeField, openMapLink, directionsLink, statusElement) => {
  const result = parseGpsCoordinates(pasteField.value);

  if (!result) {
    setStatus(statusElement, pasteField.value.trim() === '' ? 'Collez ou saisissez un point GPS.' : 'Format non reconnu.');
    updateLinks(latitudeField, longitudeField, openMapLink, directionsLink, statusElement);
    return;
  }

  latitudeField.value = normalizeCoordinate(result.latitude);
  longitudeField.value = normalizeCoordinate(result.longitude);
  updateLinks(latitudeField, longitudeField, openMapLink, directionsLink, statusElement, 'Coordonnées GPS détectées.');
};

export function initAdminPlaceGps() {
  if (typeof document === 'undefined') {
    return;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminPlaceGps, { once: true });
    return;
  }

  document.querySelectorAll('[data-gps-paste]').forEach((pasteField) => {
    if (pasteField.dataset.gpsPasteReady === 'true') {
      return;
    }

    pasteField.dataset.gpsPasteReady = 'true';

    const form = pasteField.closest('form');
    const latitudeField = form?.querySelector('[data-place-latitude]');
    const longitudeField = form?.querySelector('[data-place-longitude]');
    const statusElement = form?.querySelector('[data-place-status]');
    const openMapLink = form?.querySelector('[data-place-open-map]');
    const directionsLink = form?.querySelector('[data-place-directions]');

    if (!latitudeField || !longitudeField) {
      return;
    }

    const parseAndFill = () => fillCoordinates(pasteField, latitudeField, longitudeField, openMapLink, directionsLink, statusElement);
    const refreshManualCoordinates = () => {
      if (!updateLinks(latitudeField, longitudeField, openMapLink, directionsLink, statusElement, 'Coordonnées GPS détectées.')) {
        setStatus(statusElement, 'Collez ou saisissez un point GPS.');
      }
    };

    pasteField.addEventListener('input', parseAndFill);
    pasteField.addEventListener('paste', () => {
      window.setTimeout(parseAndFill, 0);
    });
    latitudeField.addEventListener('input', refreshManualCoordinates);
    longitudeField.addEventListener('input', refreshManualCoordinates);

    refreshManualCoordinates();
  });
}

initAdminPlaceGps();
