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

const altitudeValue = (value) => {
  if (!Number.isFinite(value)) {
    return '';
  }

  return Math.round(value).toString();
};

const measurementValue = (date = new Date()) => new Intl.DateTimeFormat('fr-FR', {
  dateStyle: 'short',
  timeStyle: 'short',
}).format(date);

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
    return 'Accès GPS refusé.';
  }

  if (error.code === error.POSITION_UNAVAILABLE) {
    return 'Position GPS indisponible.';
  }

  if (error.code === error.TIMEOUT) {
    return 'Recherche GPS en cours, aucun point précis reçu pour le moment.';
  }

  return 'Impossible de récupérer la position GPS.';
};

const setStatus = (element, message, kind = '') => {
  if (!element) {
    return;
  }

  element.textContent = message;
  element.classList.toggle('is-error', kind === 'error');
  element.classList.toggle('is-success', kind === 'success');
  element.classList.toggle('is-warning', kind === 'warning');
};

const setHidden = (element, hidden) => {
  if (element) {
    element.hidden = hidden;
  }
};

const setCopyAvailable = (buttons, available) => {
  buttons.forEach((button) => {
    setHidden(button, !available);
    button.disabled = !available;
  });
};

const validCoordinateValue = (value, min, max) => {
  const parsedValue = Number.parseFloat(String(value).replace(',', '.'));

  return Number.isFinite(parsedValue) && parsedValue >= min && parsedValue <= max;
};

const showSelectableText = (container, text) => {
  if (!container) {
    return;
  }

  let field = container.querySelector('[data-gps-copy-fallback]');
  if (!field) {
    field = document.createElement('textarea');
    field.setAttribute('readonly', 'readonly');
    field.dataset.gpsCopyFallback = 'true';
    field.style.width = '100%';
    field.style.marginTop = '8px';
    container.appendChild(field);
  }

  field.value = text;
  field.hidden = false;
  field.select();
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
  const accuracyDisplay = container.querySelector('[data-gps-accuracy-display]');
  const altitudeField = container.querySelector('[data-gps-altitude]');
  const altitudeDisplay = container.querySelector('[data-gps-altitude-display]');
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

    if (!validCoordinateValue(latitude, -90, 90) || !validCoordinateValue(longitude, -180, 180)) {
      latestCoordinates = '';
      setCopyAvailable(copyButtons, false);
      return;
    }

    latestCoordinates = `${latitude}, ${longitude}`;
    setCopyAvailable(copyButtons, true);
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

  const precisionStatus = (accuracy) => {
    if (accuracy === '') {
      return {
        kind: '',
        message: 'Meilleure position GPS conservée temporairement.',
      };
    }

    const meters = Number.parseInt(accuracy, 10);
    if (meters <= 20) {
      return {
        kind: 'success',
        message: `Meilleure position GPS conservée temporairement.\nBonne précision GPS : environ ${accuracy} m.`,
      };
    }

    if (meters <= 100) {
      return {
        kind: 'warning',
        message: `Meilleure position GPS conservée temporairement.\nPrécision GPS moyenne : environ ${accuracy} m. Vous pouvez attendre ou utiliser cette position.`,
      };
    }

    return {
      kind: 'error',
      message: `Meilleure position GPS conservée temporairement.\nPrécision GPS faible : environ ${accuracy} m. Placez-vous dehors et attendez quelques secondes.`,
    };
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
    if (accuracyDisplay) {
      accuracyDisplay.value = accuracy !== '' ? `${accuracy} m` : 'indisponible';
    }
    if (altitudeField) {
      altitudeField.value = altitudeValue(position.coords.altitude);
    }
    if (altitudeDisplay) {
      altitudeDisplay.value = altitudeField && altitudeField.value !== '' ? `${altitudeField.value} m` : 'indisponible';
    }

    dispatchFieldEvents(latitudeField);
    dispatchFieldEvents(longitudeField);
    if (accuracyField) {
      dispatchFieldEvents(accuracyField);
    }
    if (altitudeField) {
      dispatchFieldEvents(altitudeField);
    }

    latestCoordinates = `${latitude}, ${longitude}`;
    if (coordinatesElement) {
      const lines = [
        'Position relevée :',
        `Latitude : ${latitude}`,
        `Longitude : ${longitude}`,
      ];

      lines.push(accuracy !== '' ? `Précision : environ ${accuracy} m` : 'Précision : indisponible');
      lines.push(altitudeField && altitudeField.value !== '' ? `Hauteur / altitude GPS : ${altitudeField.value} m` : 'Hauteur / altitude GPS : indisponible');
      lines.push(`Mesure : ${measurementValue()}`);

      coordinatesElement.textContent = lines.join('\n');
    }

    setCopyAvailable(copyButtons, true);
    const status = precisionStatus(accuracy);
    setStatus(statusElement, status.message, status.kind);
  };

  const stopWatch = (message = 'Recherche GPS arrêtée.') => {
    if (watchId !== null) {
      navigator.geolocation.clearWatch(watchId);
      watchId = null;
    }

    renderIdleControls();
    syncCoordinatesFromFields();
    setStatus(statusElement, message);
  };

  const startWatch = () => {
    if (!navigator.geolocation) {
      setStatus(statusElement, 'La géolocalisation n\'est pas disponible sur cet appareil.', 'error');
      return;
    }

    if (watchId !== null) {
      return;
    }

    bestPosition = null;
    latestCoordinates = '';
    setCopyAvailable(copyButtons, false);
    if (coordinatesElement) {
      coordinatesElement.textContent = '';
    }

    renderActiveControls();
    setStatus(statusElement, 'Recherche GPS haute précision en cours...');

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

        if (error.code === error.TIMEOUT) {
          stopWatch(message);
          return;
        }

        setStatus(statusElement, message, 'error');
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
        setStatus(statusElement, 'Aucune coordonnée GPS à copier.', 'error');
        return;
      }

      copyText(latestCoordinates)
        .then(() => setStatus(statusElement, 'Coordonnées copiées.', 'success'))
        .catch(() => {
          showSelectableText(container, latestCoordinates);
          setStatus(statusElement, 'Copie impossible depuis ce navigateur. Le texte est sélectionnable ci-dessous.', 'error');
        });
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
