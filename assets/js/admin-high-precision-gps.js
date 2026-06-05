const WATCH_OPTIONS = {
  enableHighAccuracy: true,
  maximumAge: 0,
  timeout: 10000,
};

const coordinateValue = (value) => Number(value).toFixed(7);

const accuracyValue = (value) => {
  if (!Number.isFinite(value)) {
    return '';
  }

  return Math.round(value).toString();
};

const dispatchFieldEvents = (field) => {
  field.dispatchEvent(new Event('input', { bubbles: true }));
  field.dispatchEvent(new Event('change', { bubbles: true }));
};

const isBetterPosition = (candidate, current) => {
  if (!current) {
    return true;
  }

  const candidateAccuracy = candidate.coords.accuracy;
  const currentAccuracy = current.coords.accuracy;

  if (!Number.isFinite(candidateAccuracy)) {
    return false;
  }

  if (!Number.isFinite(currentAccuracy)) {
    return true;
  }

  return candidateAccuracy < currentAccuracy;
};

const errorMessage = (error) => {
  if (error.code === error.PERMISSION_DENIED) {
    return 'Acces GPS refuse.';
  }

  if (error.code === error.POSITION_UNAVAILABLE) {
    return 'Position GPS indisponible.';
  }

  if (error.code === error.TIMEOUT) {
    return 'Recherche GPS en cours, aucun point precis recu pour le moment.';
  }

  return 'Impossible de recuperer la position GPS.';
};

const setStatus = (element, message, kind = '') => {
  if (!element) {
    return;
  }

  element.textContent = message;
  element.classList.toggle('is-error', kind === 'error');
  element.classList.toggle('is-success', kind === 'success');
};

const setHidden = (element, hidden) => {
  if (element) {
    element.hidden = hidden;
  }
};

const copyText = (text) => {
  if (navigator.clipboard?.writeText) {
    return navigator.clipboard.writeText(text);
  }

  const field = document.createElement('textarea');
  field.value = text;
  field.setAttribute('readonly', 'readonly');
  field.style.position = 'fixed';
  field.style.opacity = '0';
  document.body.appendChild(field);
  field.select();
  document.execCommand('copy');
  field.remove();

  return Promise.resolve();
};

const initHighPrecisionGps = (container) => {
  if (container.dataset.highPrecisionGpsReady === 'true') {
    return;
  }

  container.dataset.highPrecisionGpsReady = 'true';

  const latitudeField = container.querySelector('[data-gps-latitude]');
  const longitudeField = container.querySelector('[data-gps-longitude]');
  const accuracyField = container.querySelector('[data-gps-accuracy]');
  const statusElement = container.querySelector('[data-gps-status]');
  const coordinatesElement = container.querySelector('[data-gps-coordinates]');
  const startButtons = container.querySelectorAll('[data-gps-start]');
  const stopButtons = container.querySelectorAll('[data-gps-stop]');
  const copyButtons = container.querySelectorAll('[data-gps-copy]');

  if (!latitudeField || !longitudeField || startButtons.length === 0) {
    return;
  }

  let watchId = null;
  let bestPosition = null;
  let latestCoordinates = '';

  const syncCoordinatesFromFields = () => {
    const latitude = latitudeField.value.trim();
    const longitude = longitudeField.value.trim();

    if (latitude === '' || longitude === '') {
      latestCoordinates = '';
      copyButtons.forEach((button) => setHidden(button, true));
      return;
    }

    latestCoordinates = `${latitude},${longitude}`;
    copyButtons.forEach((button) => setHidden(button, false));
  };

  const renderIdleControls = () => {
    startButtons.forEach((button) => {
      button.disabled = false;
    });
    stopButtons.forEach((button) => setHidden(button, true));
  };

  const renderActiveControls = () => {
    startButtons.forEach((button) => {
      button.disabled = true;
    });
    stopButtons.forEach((button) => setHidden(button, false));
  };

  const renderPosition = (position) => {
    const latitude = coordinateValue(position.coords.latitude);
    const longitude = coordinateValue(position.coords.longitude);
    const accuracy = accuracyValue(position.coords.accuracy);

    latitudeField.value = latitude;
    longitudeField.value = longitude;
    if (accuracyField) {
      accuracyField.value = accuracy;
    }

    dispatchFieldEvents(latitudeField);
    dispatchFieldEvents(longitudeField);
    if (accuracyField) {
      dispatchFieldEvents(accuracyField);
    }

    latestCoordinates = `${latitude},${longitude}`;
    if (coordinatesElement) {
      coordinatesElement.textContent = accuracy !== ''
        ? `${latestCoordinates} - precision ${accuracy} m`
        : latestCoordinates;
    }

    copyButtons.forEach((button) => setHidden(button, false));
    setStatus(statusElement, 'Meilleure position GPS conservee. Enregistrez pour la garder.', 'success');
  };

  const stopWatch = (message = 'Recherche GPS arretee.') => {
    if (watchId !== null) {
      navigator.geolocation.clearWatch(watchId);
      watchId = null;
    }

    renderIdleControls();
    setStatus(statusElement, message);
  };

  const startWatch = () => {
    if (!navigator.geolocation) {
      setStatus(statusElement, 'La geolocalisation n\'est pas disponible sur cet appareil.', 'error');
      return;
    }

    if (watchId !== null) {
      return;
    }

    bestPosition = null;
    latestCoordinates = '';
    copyButtons.forEach((button) => setHidden(button, true));
    if (coordinatesElement) {
      coordinatesElement.textContent = '';
    }

    renderActiveControls();
    setStatus(statusElement, 'Recherche GPS haute precision en cours...');

    watchId = navigator.geolocation.watchPosition(
      (position) => {
        if (!isBetterPosition(position, bestPosition)) {
          return;
        }

        bestPosition = position;
        renderPosition(position);
      },
      (error) => {
        const message = errorMessage(error);

        if (error.code === error.PERMISSION_DENIED) {
          stopWatch(message);
          setStatus(statusElement, message, 'error');
          return;
        }

        setStatus(statusElement, message, error.code === error.TIMEOUT ? '' : 'error');
      },
      WATCH_OPTIONS
    );
  };

  startButtons.forEach((button) => {
    button.addEventListener('click', startWatch);
  });

  stopButtons.forEach((button) => {
    button.addEventListener('click', () => stopWatch());
  });

  copyButtons.forEach((button) => {
    button.addEventListener('click', () => {
      if (latestCoordinates === '') {
        setStatus(statusElement, 'Aucune coordonnee GPS a copier.', 'error');
        return;
      }

      copyText(latestCoordinates)
        .then(() => setStatus(statusElement, 'Coordonnees copiees.', 'success'))
        .catch(() => setStatus(statusElement, 'Copie impossible depuis ce navigateur.', 'error'));
    });
  });

  latitudeField.addEventListener('input', syncCoordinatesFromFields);
  longitudeField.addEventListener('input', syncCoordinatesFromFields);

  renderIdleControls();
  syncCoordinatesFromFields();

  window.addEventListener('pagehide', () => {
    if (watchId !== null) {
      navigator.geolocation.clearWatch(watchId);
    }
  });
};

export function initAdminHighPrecisionGps() {
  if (typeof document === 'undefined') {
    return;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminHighPrecisionGps, { once: true });
    return;
  }

  document
    .querySelectorAll('[data-high-precision-gps], [data-gps-form]')
    .forEach(initHighPrecisionGps);
}

initAdminHighPrecisionGps();
