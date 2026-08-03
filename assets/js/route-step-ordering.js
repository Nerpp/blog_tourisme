import Sortable from 'sortablejs';

import { setAdminHighPrecisionGpsCoordinates } from './admin-high-precision-gps.js';
import '../styles/route-step-ordering.css';

const selector = {
  root: '[data-route-step-ordering]',
  list: '[data-route-step-list]',
  slot: '[data-route-step-slot]',
  step: '[data-route-step]',
};

const directChildren = (element, childSelector) => Array.from(element.children)
  .filter((child) => child.matches(childSelector));

const stepInSlot = (slot) => slot.querySelector(selector.step);

const coordinate = (field, minimum, maximum) => {
  if (!field || String(field.value).trim() === '') {
    return null;
  }

  const parsed = Number(String(field.value).trim().replace(',', '.'));

  return Number.isFinite(parsed) && parsed >= minimum && parsed <= maximum ? parsed : null;
};

const setStatus = (root, message, kind = '') => {
  const status = root.querySelector('[data-route-step-order-status]');
  if (!status) {
    return;
  }

  status.textContent = message;
  status.classList.toggle('is-saving', kind === 'saving');
  status.classList.toggle('is-saved', kind === 'saved');
  status.classList.toggle('is-error', kind === 'error');
};

const announce = (root, message) => {
  const announcer = root.querySelector('[data-route-step-announcer]');
  if (!announcer) {
    return;
  }

  announcer.textContent = '';
  window.requestAnimationFrame(() => {
    announcer.textContent = message;
  });
};

const initRouteStepOrdering = (root) => {
  if (root.dataset.routeStepOrderingReady === 'true') {
    return;
  }
  root.dataset.routeStepOrderingReady = 'true';

  const list = root.querySelector(selector.list);
  if (!list) {
    return;
  }

  const positionEditor = root.querySelector('[data-route-step-position-editor]');
  const positionPicker = positionEditor?.querySelector('[data-location-picker]') || null;
  const positionEditorTitle = positionEditor?.querySelector('[data-route-step-position-editor-number]') || null;
  const positionEditorStatus = positionEditor?.querySelector('[data-route-step-position-status]') || null;
  const mainLocationPicker = Array.from(document.querySelectorAll('[data-location-picker]')).find((picker) => (
    picker !== positionPicker
    && picker.querySelector('[name="locationLatitude"]')
    && picker.querySelector('[name="locationLongitude"]')
  )) || null;

  let applyingInheritedCoordinates = false;
  let isSaving = false;
  let pendingState = null;
  let acknowledgedState = null;
  let serverStateUncertain = false;
  let coordinatesSaving = false;
  let coordinatesServerStateUncertain = false;
  let coordinatesStateStale = false;
  let coordinateUiStateConflict = false;
  let activePositionAdjustment = null;
  let uncertainPositionAdjustment = null;
  let stalePositionAdjustment = null;
  let conflictingPositionAdjustment = null;
  let orderSettlementWaiters = [];
  let mainLocationPickerDirty = false;
  let synchronizingMainLocationPicker = false;
  const controlLockStates = new WeakMap();
  const pickerLockReasons = new WeakMap();
  const sortableLockReasons = new Set();

  const slots = () => directChildren(list, selector.slot);
  const steps = () => slots().map(stepInSlot).filter(Boolean);
  const routeMutationBlockedByCoordinates = () => coordinatesSaving
    || coordinatesServerStateUncertain
    || coordinatesStateStale
    || coordinateUiStateConflict;

  const setControlLocked = (control, reason, locked) => {
    if (!(control instanceof HTMLButtonElement)
      && !(control instanceof HTMLInputElement)
      && !(control instanceof HTMLSelectElement)
      && !(control instanceof HTMLTextAreaElement)) {
      return;
    }

    let state = controlLockStates.get(control);
    if (!state && !locked) {
      return;
    }
    if (!state) {
      state = { initiallyDisabled: control.disabled, reasons: new Set() };
      controlLockStates.set(control, state);
    }
    if (locked) {
      state.reasons.add(reason);
    } else {
      state.reasons.delete(reason);
    }

    control.disabled = state.initiallyDisabled || state.reasons.size > 0;
    if (control.disabled) {
      control.setAttribute('aria-disabled', 'true');
    } else {
      control.removeAttribute('aria-disabled');
    }
    if (state.reasons.size === 0) {
      controlLockStates.delete(control);
    }
  };

  const setControlsLocked = (controls, reason, locked) => {
    controls.forEach((control) => setControlLocked(control, reason, locked));
  };

  const setPickerLocked = (picker, reason, locked, allowValidation = false) => {
    if (!picker) {
      return;
    }

    const controls = Array.from(picker.querySelectorAll('button, input, select, textarea'))
      .filter((control) => !allowValidation || !control.matches('[data-validate-point]'));
    setControlsLocked(controls, reason, locked);

    let reasons = pickerLockReasons.get(picker);
    if (!reasons) {
      reasons = new Map();
      pickerLockReasons.set(picker, reasons);
    }
    if (locked) {
      reasons.set(reason, allowValidation);
    } else {
      reasons.delete(reason);
    }
    picker.classList.toggle('is-coordinate-locked', reasons.size > 0);
    picker.locationGeopointPicker?.setLocked?.(
      reasons.size > 0,
      { allowValidation: reasons.size > 0 && Array.from(reasons.values()).every(Boolean) },
    );
    if (reasons.size === 0) {
      pickerLockReasons.delete(picker);
    }
  };

  const setPositionStatus = (message, kind = '') => {
    if (!positionEditorStatus) {
      return;
    }

    positionEditorStatus.textContent = message;
    positionEditorStatus.classList.toggle('is-saving', kind === 'saving');
    positionEditorStatus.classList.toggle('is-success', kind === 'success');
    positionEditorStatus.classList.toggle('is-error', kind === 'error');
  };

  const stepFields = (step) => ({
    latitude: step.querySelector('[data-gps-latitude]'),
    longitude: step.querySelector('[data-gps-longitude]'),
    accuracy: step.querySelector('[data-gps-accuracy]'),
    inherited: step.querySelector('[data-route-step-inherited-input]'),
  });

  const normalizedCoordinateState = (latitude, longitude, accuracy) => {
    const latitudeValue = latitude ?? '';
    const longitudeValue = longitude ?? '';
    const accuracyValue = accuracy ?? '';
    const hasLatitude = String(latitudeValue).trim() !== '';
    const hasLongitude = String(longitudeValue).trim() !== '';
    const hasAccuracy = String(accuracyValue).trim() !== '';
    const normalizedLatitude = coordinate({ value: latitudeValue }, -90, 90);
    const normalizedLongitude = coordinate({ value: longitudeValue }, -180, 180);
    if (normalizedLatitude === null || normalizedLongitude === null) {
      return !hasLatitude && !hasLongitude && !hasAccuracy
        ? { latitude: null, longitude: null, accuracy: null }
        : null;
    }

    const normalizedAccuracy = coordinate({ value: accuracyValue }, 0, Number.MAX_VALUE);
    if (hasAccuracy && normalizedAccuracy === null) {
      return null;
    }

    return {
      latitude: normalizedLatitude,
      longitude: normalizedLongitude,
      accuracy: normalizedAccuracy,
    };
  };

  const sameCoordinateState = (first, second) => Boolean(first && second)
    && first.latitude === second.latitude
    && first.longitude === second.longitude
    && first.accuracy === second.accuracy;

  const pickerState = (picker) => {
    if (!picker) {
      return null;
    }

    const validated = normalizedCoordinateState(
      picker.querySelector('[data-latitude-input]')?.value,
      picker.querySelector('[data-longitude-input]')?.value,
      picker.querySelector('[data-gps-accuracy-input]')?.value,
    );
    const pending = picker.locationGeopointPicker?.getCoordinates?.() ?? null;
    const normalizedPending = pending === null
      ? { latitude: null, longitude: null, accuracy: null }
      : normalizedCoordinateState(pending.latitude, pending.longitude, pending.accuracy);

    return { validated, pending: normalizedPending };
  };

  const samePickerState = (first, second) => Boolean(first && second)
    && sameCoordinateState(first.validated, second.validated)
    && sameCoordinateState(first.pending, second.pending);

  const primaryStepId = () => {
    const id = Number.parseInt(root.dataset.primaryStepId || '', 10);

    return Number.isInteger(id) ? id : null;
  };

  const syncPrimaryStepIdFromOrder = (orderedSteps = steps()) => {
    const persistedPrimaryStep = orderedSteps.find((step) => step.dataset.persistedStepType === 'start')
      || orderedSteps[0]
      || null;
    root.dataset.primaryStepId = persistedPrimaryStep?.dataset.stepId || '';
  };

  const markMainLocationPickerDirty = () => {
    if (mainLocationPicker?.dataset.locationPickerReady === 'true'
      && !synchronizingMainLocationPicker) {
      mainLocationPickerDirty = true;
    }
  };

  mainLocationPicker?.querySelector('[data-latitude-input]')
    ?.addEventListener('input', markMainLocationPickerDirty);
  mainLocationPicker?.querySelector('[data-longitude-input]')
    ?.addEventListener('input', markMainLocationPickerDirty);
  mainLocationPicker?.querySelector('[data-gps-accuracy-input]')
    ?.addEventListener('input', markMainLocationPickerDirty);
  mainLocationPicker?.addEventListener(
    'location-geopoint-picker:point-moved',
    markMainLocationPickerDirty,
  );

  const snapshot = () => steps().map((step) => {
    const fields = stepFields(step);

    return {
      id: Number.parseInt(step.dataset.stepId || '', 10),
      latitude: fields.latitude?.value || '',
      longitude: fields.longitude?.value || '',
      accuracy: fields.accuracy?.value || '',
      inherited: step.dataset.gpsInherited === 'true',
      gpsDirty: step.dataset.gpsDirty === 'true',
    };
  });

  const canonicalState = (state, orderedIds) => {
    const byId = new Map(state.map((item) => [item.id, item]));

    return orderedIds.map((id) => byId.get(id)).filter(Boolean);
  };

  const applyOrder = (state) => {
    const slotsById = new Map(slots().map((slot) => {
      const step = stepInSlot(slot);
      return [Number.parseInt(step?.dataset.stepId || '', 10), slot];
    }));

    state.forEach((item) => {
      const slot = slotsById.get(item.id);
      if (slot) {
        list.append(slot);
      }
    });
  };

  const applyGpsItem = (step, item, source = 'order-canonical') => {
    const fields = stepFields(step);
    const gpsForm = step.querySelector('[data-high-precision-gps]');
    applyingInheritedCoordinates = true;
    try {
      if (gpsForm) {
        setAdminHighPrecisionGpsCoordinates(
          gpsForm,
          item.latitude === '' ? null : item.latitude,
          item.longitude === '' ? null : item.longitude,
          {
            accuracy: item.accuracy === '' ? null : item.accuracy,
            inherited: item.inherited,
            source,
          },
        );
      } else {
        if (fields.latitude) fields.latitude.value = item.latitude;
        if (fields.longitude) fields.longitude.value = item.longitude;
        if (fields.accuracy) fields.accuracy.value = item.accuracy;
      }
    } finally {
      applyingInheritedCoordinates = false;
    }
    step.dataset.gpsInherited = item.inherited ? 'true' : 'false';
    step.dataset.gpsDirty = item.gpsDirty ? 'true' : 'false';
    if (fields.inherited) fields.inherited.value = item.inherited ? '1' : '0';
  };

  const gpsStateEquals = (first, second) => Boolean(first && second)
    && first.latitude === second.latitude
    && first.longitude === second.longitude
    && first.accuracy === second.accuracy
    && first.inherited === second.inherited
    && first.gpsDirty === second.gpsDirty;

  const stepName = (step) => {
    const title = step.querySelector('[data-route-step-title-input]')?.value.trim();
    if (title) {
      return title;
    }

    const selectedOption = step.querySelector('[data-route-step-type-input]')?.selectedOptions?.[0];

    return selectedOption?.textContent?.trim() || 'Point GPS';
  };

  const coordinatesOf = (step) => {
    if (!step) {
      return null;
    }
    const fields = stepFields(step);
    const latitude = coordinate(fields.latitude, -90, 90);
    const longitude = coordinate(fields.longitude, -180, 180);
    if (latitude === null || longitude === null) {
      return null;
    }

    const accuracy = coordinate(fields.accuracy, 0, Number.MAX_VALUE);

    return { latitude, longitude, accuracy };
  };

  const predecessor = (orderedSteps, index) => {
    if (index > 0) {
      return orderedSteps[index - 1];
    }

    return orderedSteps.find((candidate) => (
      candidate !== orderedSteps[index]
      && candidate.dataset.stepType === 'start'
      && coordinatesOf(candidate) !== null
    )) || null;
  };

  const syncPositionButton = (step, fallbackCoordinates = null) => {
    const button = step.querySelector('[data-route-step-adjust-position]');
    if (!button) {
      return;
    }

    const fields = stepFields(step);
    button.dataset.latitude = fields.latitude?.value || '';
    button.dataset.longitude = fields.longitude?.value || '';
    button.dataset.accuracy = fields.accuracy?.value || '';
    button.dataset.coordinatesInherited = step.dataset.gpsInherited === 'true' ? 'true' : 'false';
    button.dataset.fallbackLatitude = fallbackCoordinates === null ? '' : String(fallbackCoordinates.latitude);
    button.dataset.fallbackLongitude = fallbackCoordinates === null ? '' : String(fallbackCoordinates.longitude);
    button.setAttribute('aria-label', `Ajuster la position GPS de l’étape ${step.dataset.stepPosition || ''}`.trim());
  };

  const updateStepMapLink = (step, latitude, longitude) => {
    const link = step.querySelector('[data-route-step-map-link]');
    if (!link) {
      return;
    }

    link.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(latitude)},${encodeURIComponent(longitude)}`;
    link.hidden = false;
    link.removeAttribute('aria-disabled');
  };

  const renderStepMapLink = (step, coordinates) => {
    const link = step.querySelector('[data-route-step-map-link]');
    if (!link) {
      return;
    }
    if (coordinates === null) {
      link.href = '#';
      link.hidden = true;
      link.setAttribute('aria-disabled', 'true');
      return;
    }

    updateStepMapLink(step, coordinates.latitude, coordinates.longitude);
  };

  const syncPrimaryLocationPicker = (adjustment, state) => {
    if (!adjustment.isPrimaryStep) {
      return true;
    }
    if (!mainLocationPicker
      || typeof mainLocationPicker.locationGeopointPicker?.setCoordinates !== 'function'
      || adjustment.mainPickerDirtyAtOpen
      || mainLocationPickerDirty
      || !samePickerState(pickerState(mainLocationPicker), adjustment.mainPickerStateAtOpen)) {
      return false;
    }

    try {
      synchronizingMainLocationPicker = true;
      mainLocationPicker.locationGeopointPicker?.setCoordinates(
        state.latitude,
        state.longitude,
        {
          accuracy: state.accuracy === '' ? null : state.accuracy,
          zoom: 17,
          source: 'route-step-map',
          statusMessage: 'Position principale synchronisée depuis l’étape GPS.',
        },
      );
    } catch {
      return false;
    } finally {
      synchronizingMainLocationPicker = false;
    }
    mainLocationPickerDirty = false;

    return true;
  };

  const renderGpsState = (step) => {
    const inherited = step.dataset.gpsInherited === 'true';
    const badge = step.querySelector('[data-route-step-gps-badge]');
    const inheritedInput = step.querySelector('[data-route-step-inherited-input]');
    if (inheritedInput) {
      inheritedInput.value = inherited ? '1' : '0';
    }
    if (badge) {
      badge.textContent = inherited ? 'GPS repris depuis l’étape précédente' : 'GPS personnalisé';
      badge.classList.toggle('is-inherited', inherited);
      badge.classList.toggle('is-customized', !inherited);
    }
  };

  const updateMediaPointLabels = (orderedSteps) => {
    const optionsByPointId = new Map();
    document.querySelectorAll('option[value^="point:"]').forEach((option) => {
      const pointId = option.value.slice('point:'.length);
      const pointOptions = optionsByPointId.get(pointId) || [];
      pointOptions.push(option);
      optionsByPointId.set(pointId, pointOptions);
    });

    orderedSteps.forEach((step, index) => {
      const id = step.dataset.stepId;
      const label = `Étape ${index + 1} — ${stepName(step)}`;
      (optionsByPointId.get(id) || []).forEach((option) => {
        option.textContent = label;
      });
    });
  };

  const render = () => {
    const orderedSlots = slots();
    const orderedSteps = orderedSlots.map(stepInSlot).filter(Boolean);
    const firstInsertion = root.querySelector('.route-step-insertion--first');
    const startCoordinates = coordinatesOf(orderedSteps.find((step) => step.dataset.stepType === 'start'));
    orderedSteps.forEach((step, index) => {
      const position = index + 1;
      const name = stepName(step);
      const slot = orderedSlots[index];
      const number = step.querySelector('[data-route-step-number]');
      const nameElement = step.querySelector('[data-route-step-name]');
      const up = step.querySelector('[data-route-step-up]');
      const down = step.querySelector('[data-route-step-down]');
      const handle = step.querySelector('[data-route-step-drag-handle]');
      const insertion = slot.querySelector('[data-route-step-insertion]');
      const insertionPosition = insertion?.querySelector('[data-route-step-insert-position]');
      const insertionLabel = insertion?.querySelector('[data-route-step-insert-label]');
      const copyPrevious = step.querySelector('[data-route-step-copy-previous]');
      const previousStep = predecessor(orderedSteps, index);
      const previousCoordinates = coordinatesOf(previousStep);
      const currentCoordinates = coordinatesOf(step);
      const duplicateWarning = step.querySelector('[data-route-step-duplicate-warning]');

      step.dataset.stepPosition = String(position);
      step.dataset.predecessorId = previousStep?.dataset.stepId || '';
      if (number) number.textContent = `Étape ${position}`;
      if (nameElement) nameElement.textContent = name;
      if (up) {
        up.disabled = index === 0;
        up.setAttribute('aria-label', `Déplacer l’étape ${position} vers le haut`);
      }
      if (down) {
        down.disabled = index === orderedSteps.length - 1;
        down.setAttribute('aria-label', `Déplacer l’étape ${position} vers le bas`);
      }
      handle?.setAttribute('aria-label', `Déplacer l’étape ${position} : ${name}`);
      if (insertionPosition) insertionPosition.value = String(position + 1);
      if (insertionLabel) {
        insertionLabel.textContent = index === orderedSteps.length - 1
          ? 'Ajouter à la fin'
          : `Ajouter après l’étape ${position}`;
      }
      if (copyPrevious) {
        copyPrevious.disabled = previousCoordinates === null;
      }
      if (duplicateWarning) {
        duplicateWarning.hidden = previousCoordinates === null
          || currentCoordinates === null
          || previousCoordinates.latitude !== currentCoordinates.latitude
          || previousCoordinates.longitude !== currentCoordinates.longitude;
      }
      renderGpsState(step);
      syncPositionButton(step, startCoordinates);
      renderStepMapLink(step, currentCoordinates);
      if (activePositionAdjustment?.step === step && positionEditorTitle) {
        positionEditorTitle.textContent = String(position);
      }
    });

    const firstLabel = firstInsertion?.querySelector('[data-route-step-insert-label]');
    if (firstLabel) {
      firstLabel.textContent = orderedSteps.length === 0 ? 'Ajouter la première étape' : 'Ajouter au début';
    }

    updateMediaPointLabels(orderedSteps);
    if (coordinatesSaving && activePositionAdjustment) {
      setCoordinateMutationLocked(true, activePositionAdjustment, 'coordinate-request');
    }
    if (coordinatesServerStateUncertain && uncertainPositionAdjustment) {
      setCoordinateMutationLocked(true, uncertainPositionAdjustment, 'coordinate-uncertain', true);
    }
    if (coordinatesStateStale && stalePositionAdjustment) {
      setCoordinateMutationLocked(true, stalePositionAdjustment, 'coordinate-stale');
    }
    if (coordinateUiStateConflict && conflictingPositionAdjustment) {
      setCoordinateMutationLocked(true, conflictingPositionAdjustment, 'coordinate-ui-conflict');
    }
    document.dispatchEvent(new CustomEvent('route-step-order:changed', {
      detail: {
        orderedIds: orderedSteps.map((step) => Number.parseInt(step.dataset.stepId || '', 10)),
        points: orderedSteps.map((step, index) => ({
          id: Number.parseInt(step.dataset.stepId || '', 10),
          position: index + 1,
          ...coordinatesOf(step),
        })),
      },
    }));
  };

  const markCustomized = (step) => {
    step.dataset.gpsInherited = 'false';
    step.dataset.gpsDirty = 'true';
    renderGpsState(step);
  };

  const applyPredecessorCoordinates = (step, sourceStep, inherited = true) => {
    const source = coordinatesOf(sourceStep);
    const gpsForm = step.querySelector('[data-high-precision-gps]');
    if (!gpsForm) {
      return;
    }

    applyingInheritedCoordinates = true;
    try {
      setAdminHighPrecisionGpsCoordinates(
        gpsForm,
        source?.latitude ?? null,
        source?.longitude ?? null,
        {
          accuracy: source?.accuracy ?? null,
          inherited,
          source: inherited ? 'previous-route-step' : 'manual-previous-route-step',
          statusMessage: source
            ? 'Coordonnées reprises depuis l’étape précédente. Enregistrez cette personnalisation après vérification.'
            : 'Aucune coordonnée précédente disponible. Utilisez le système GPS existant.',
          statusKind: source ? 'success' : 'warning',
        },
      );
    } finally {
      applyingInheritedCoordinates = false;
    }
    step.dataset.gpsInherited = inherited ? 'true' : 'false';
    step.dataset.gpsDirty = inherited ? 'false' : 'true';
    renderGpsState(step);
  };

  const refreshInheritedCoordinates = () => {
    const orderedSteps = steps();
    orderedSteps.forEach((step, index) => {
      if (step.dataset.gpsInherited === 'true') {
        applyPredecessorCoordinates(step, predecessor(orderedSteps, index));
      }
    });
  };

  const restoreAcknowledgedState = (message, requestedState) => {
    if (acknowledgedState) {
      const currentById = new Map(snapshot().map((item) => [item.id, item]));
      const requestedById = new Map(requestedState.map((item) => [item.id, item]));
      applyOrder(acknowledgedState);
      syncPrimaryStepIdFromOrder();
      const stepsById = new Map(steps().map((step) => [Number(step.dataset.stepId), step]));
      acknowledgedState.forEach((acknowledgedItem) => {
        const currentItem = currentById.get(acknowledgedItem.id);
        const requestedItem = requestedById.get(acknowledgedItem.id);
        const step = stepsById.get(acknowledgedItem.id);
        if (step && requestedItem && !requestedItem.gpsDirty && gpsStateEquals(currentItem, requestedItem)) {
          applyGpsItem(step, acknowledgedItem, 'order-rollback');
        }
      });
      render();
    }
    setStatus(root, message, 'error');
    announce(root, message);
  };

  const responseState = (payload, requestedState) => {
    if (!payload?.success || !Array.isArray(payload.steps) || !Array.isArray(payload.gpsStates)) {
      return null;
    }

    const orderedIds = payload.steps.map((step, index) => (
      Number.isInteger(step?.id) && step.position === index + 1 ? step.id : null
    ));
    if (orderedIds.includes(null) || orderedIds.length !== requestedState.length) {
      return null;
    }

    const expected = [...requestedState.map((item) => item.id)].sort((first, second) => first - second);
    const received = [...orderedIds].sort((first, second) => first - second);
    if (expected.some((id, index) => id !== received[index])) {
      return null;
    }

    const gpsStates = payload.gpsStates.map((item) => {
      const validId = Number.isInteger(item?.id);
      const validLatitude = item?.latitude === null
        || (Number.isFinite(item?.latitude) && item.latitude >= -90 && item.latitude <= 90);
      const validLongitude = item?.longitude === null
        || (Number.isFinite(item?.longitude) && item.longitude >= -180 && item.longitude <= 180);
      const validAccuracy = item?.accuracy === null || (Number.isFinite(item?.accuracy) && item.accuracy >= 0);
      const completeCoordinatePair = (item?.latitude === null) === (item?.longitude === null);
      const coherentAccuracy = item?.latitude !== null || item?.accuracy === null;
      if (!validId || !validLatitude || !validLongitude || !validAccuracy || !completeCoordinatePair
        || !coherentAccuracy || typeof item?.inherited !== 'boolean') {
        return null;
      }

      return {
        id: item.id,
        latitude: item.latitude === null ? '' : String(item.latitude),
        longitude: item.longitude === null ? '' : String(item.longitude),
        accuracy: item.accuracy === null ? '' : String(item.accuracy),
        inherited: item.inherited,
        gpsDirty: false,
      };
    });
    if (gpsStates.includes(null) || gpsStates.length !== requestedState.length) {
      return null;
    }

    const gpsIds = [...gpsStates.map((item) => item.id)].sort((first, second) => first - second);
    if (expected.some((id, index) => id !== gpsIds[index])) {
      return null;
    }

    return {
      orderedIds,
      gpsStates: canonicalState(gpsStates, orderedIds),
    };
  };

  const applyCanonicalGpsWhenUnchanged = (serverState, requestedState) => {
    const currentById = new Map(snapshot().map((item) => [item.id, item]));
    const requestedById = new Map(requestedState.map((item) => [item.id, item]));
    const stepsById = new Map(steps().map((step) => [Number(step.dataset.stepId), step]));
    serverState.forEach((serverItem) => {
      const currentItem = currentById.get(serverItem.id);
      const requestedItem = requestedById.get(serverItem.id);
      const step = stepsById.get(serverItem.id);
      if (step && gpsStateEquals(currentItem, requestedItem)) {
        applyGpsItem(step, serverItem);
      }
    });
  };

  const setMutationFormsLocked = (locked, reason = 'order-request') => {
    setControlsLocked(
      Array.from(root.querySelectorAll('form button[type="submit"]')),
      reason,
      locked,
    );
  };

  const waitForOrderSettlement = () => {
    if (!isSaving && pendingState === null) {
      return Promise.resolve();
    }

    return new Promise((resolve) => {
      orderSettlementWaiters.push(resolve);
    });
  };

  const notifyOrderSettlement = () => {
    const waiters = orderSettlementWaiters;
    orderSettlementWaiters = [];
    waiters.forEach((resolve) => resolve());
  };

  const setSortableLocked = (locked, reason) => {
    if (locked) {
      sortableLockReasons.add(reason);
    } else {
      sortableLockReasons.delete(reason);
    }
    sortable.option('disabled', sortableLockReasons.size > 0);
  };

  const setCoordinateMutationLocked = (
    locked,
    adjustment,
    reason = 'coordinate-request',
    allowRetry = false,
  ) => {
    const routeControls = Array.from(root.querySelectorAll([
      '[data-route-step-up]',
      '[data-route-step-down]',
      '[data-route-step-drag-handle]',
      '[data-route-step-copy-previous]',
      '[data-route-step-adjust-position]',
      '[data-route-step-position-editor-close]',
    ].join(',')));
    if (adjustment?.step) {
      routeControls.push(...adjustment.step.querySelectorAll([
        '[data-gps-latitude]',
        '[data-gps-longitude]',
        '[data-gps-accuracy]',
        '[data-gps-start]',
        '[data-gps-stop]',
        '[data-gps-copy]',
      ].join(',')));
    }
    setControlsLocked(routeControls, reason, locked);
    setPickerLocked(positionPicker, reason, locked, allowRetry);
    setPickerLocked(mainLocationPicker, reason, locked);
    setSortableLocked(locked, reason);
    setMutationFormsLocked(locked, reason);
    if (reason === 'coordinate-request') {
      positionEditor?.classList.toggle('is-coordinate-saving', locked);
    }
    if (reason === 'coordinate-uncertain') {
      positionEditor?.classList.toggle('is-coordinate-uncertain', locked);
    }
  };

  const setCoordinatesServerStateUncertain = (uncertain, adjustment) => {
    const lockedAdjustment = uncertain ? adjustment : uncertainPositionAdjustment;
    if (uncertain) {
      uncertainPositionAdjustment = adjustment;
    }
    coordinatesServerStateUncertain = uncertain;
    setCoordinateMutationLocked(
      uncertain,
      lockedAdjustment,
      'coordinate-uncertain',
      true,
    );
    if (positionEditor) {
      positionEditor.toggleAttribute('data-server-state-uncertain', uncertain);
    }
    if (!uncertain) {
      uncertainPositionAdjustment = null;
    }
  };

  const setCoordinatesStateStale = (stale, adjustment) => {
    const lockedAdjustment = stale ? adjustment : stalePositionAdjustment;
    if (stale) {
      stalePositionAdjustment = adjustment;
    }
    coordinatesStateStale = stale;
    setCoordinateMutationLocked(stale, lockedAdjustment, 'coordinate-stale');
    positionEditor?.toggleAttribute('data-coordinate-state-stale', stale);
    positionEditor?.classList.toggle('is-coordinate-stale', stale);
    if (!stale) {
      stalePositionAdjustment = null;
    }
  };

  const setCoordinateUiStateConflict = (conflict, adjustment) => {
    const lockedAdjustment = conflict ? adjustment : conflictingPositionAdjustment;
    if (conflict) {
      conflictingPositionAdjustment = adjustment;
    }
    coordinateUiStateConflict = conflict;
    setCoordinateMutationLocked(conflict, lockedAdjustment, 'coordinate-ui-conflict');
    positionEditor?.toggleAttribute('data-coordinate-ui-conflict', conflict);
    positionEditor?.classList.toggle('is-coordinate-ui-conflict', conflict);
    if (!conflict) {
      conflictingPositionAdjustment = null;
    }
  };

  const drainSaveQueue = async () => {
    if (isSaving) {
      return;
    }
    isSaving = true;
    setMutationFormsLocked(true);

    try {
      while (pendingState !== null) {
        const requestedState = pendingState;
        pendingState = null;
        setStatus(root, 'Enregistrement de l’ordre…', 'saving');

        try {
          const abortController = new AbortController();
          const timeoutId = window.setTimeout(() => abortController.abort(), 15000);
          let response;
          let payload;
          try {
            response = await fetch(root.dataset.reorderUrl, {
              method: 'POST',
              headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': root.dataset.reorderToken || '',
                'X-Requested-With': 'XMLHttpRequest',
              },
              signal: abortController.signal,
              body: JSON.stringify({
                orderedIds: requestedState.map((item) => item.id),
                customizedGps: requestedState
                  .filter((item) => item.gpsDirty)
                  .map((item) => ({
                    id: item.id,
                    latitude: item.latitude === '' ? null : item.latitude,
                    longitude: item.longitude === '' ? null : item.longitude,
                    accuracy: item.accuracy === '' ? null : item.accuracy,
                })),
              }),
            });
            payload = await response.json().catch(() => null);
          } finally {
            window.clearTimeout(timeoutId);
          }
          if (!response.ok) {
            const rejection = new Error(payload?.error || 'L’ordre n’a pas pu être enregistré.');
            const controlledRejectionStatuses = [400, 403, 409, 422];
            rejection.reorderRejected = controlledRejectionStatuses.includes(response.status)
              && payload?.success === false
              && typeof payload?.error === 'string';
            throw rejection;
          }

          const serverState = responseState(payload, requestedState);
          if (serverState === null) {
            throw new Error('La réponse du serveur ne permet pas de confirmer l’ordre enregistré.');
          }

          serverStateUncertain = false;
          acknowledgedState = serverState.gpsStates;
          if (pendingState === null) {
            applyOrder(acknowledgedState);
            syncPrimaryStepIdFromOrder();
            applyCanonicalGpsWhenUnchanged(acknowledgedState, requestedState);
            render();
            setStatus(root, 'Ordre enregistré', 'saved');
          }
        } catch (error) {
          if (pendingState === null) {
            if (error?.reorderRejected === true && !serverStateUncertain) {
              restoreAcknowledgedState(
                error.message || 'L’ordre précédent a été restauré.',
                requestedState,
              );
            } else {
              serverStateUncertain = true;
              const message = 'Impossible de confirmer l’ordre côté serveur. Vos saisies sont conservées ; réessayez un déplacement ou rechargez la page.';
              setStatus(root, message, 'error');
              announce(root, message);
            }
          } else if (error?.reorderRejected !== true) {
            serverStateUncertain = true;
          }
        }
      }
    } finally {
      isSaving = false;
      setMutationFormsLocked(false);
      notifyOrderSettlement();
    }
  };

  const saveCurrentOrder = () => {
    if (routeMutationBlockedByCoordinates()) {
      return;
    }
    pendingState = snapshot();
    void drainSaveQueue();
  };

  const completeMove = (movedStep) => {
    if (routeMutationBlockedByCoordinates()) {
      if (acknowledgedState) {
        applyOrder(acknowledgedState);
        syncPrimaryStepIdFromOrder();
      }
      render();
      return;
    }
    refreshInheritedCoordinates();
    render();
    const position = steps().indexOf(movedStep) + 1;
    announce(root, `L’étape ${stepName(movedStep)} est maintenant en position ${position}.`);
    saveCurrentOrder();
  };

  const acknowledgedItem = (stepId) => (
    Array.isArray(acknowledgedState)
      ? acknowledgedState.find((item) => item.id === stepId) || null
      : null
  );

  const primaryPickerIsSafe = (adjustment) => {
    if (!adjustment.isPrimaryStep) {
      return true;
    }

    return !adjustment.mainPickerDirtyAtOpen
      && !mainLocationPickerDirty
      && samePickerState(
        pickerState(mainLocationPicker),
        adjustment.mainPickerStateAtOpen,
      );
  };

  const closePositionEditor = (restoreFocus = true) => {
    if (!positionEditor || routeMutationBlockedByCoordinates()) {
      return;
    }

    const trigger = activePositionAdjustment?.button || null;
    trigger?.setAttribute('aria-expanded', 'false');
    positionEditor.hidden = true;
    delete positionEditor.dataset.activeStepId;
    activePositionAdjustment = null;
    setPositionStatus('');
    if (restoreFocus && trigger instanceof HTMLElement) {
      trigger.scrollIntoView({ behavior: 'auto', block: 'nearest' });
      trigger.focus();
    }
  };

  const openPositionEditor = (button) => {
    if (!positionEditor || !positionPicker) {
      setStatus(root, 'Le sélecteur cartographique GPS est indisponible.', 'error');
      announce(root, 'Le sélecteur cartographique GPS est indisponible.');
      return;
    }

    const step = button.closest(selector.step);
    const stepId = Number.parseInt(step?.dataset.stepId || '', 10);
    if (!step || !Number.isInteger(stepId)) {
      return;
    }

    activePositionAdjustment?.button?.setAttribute('aria-expanded', 'false');
    activePositionAdjustment = {
      button,
      step,
      stepId,
      isPrimaryStep: stepId === primaryStepId(),
      mainPickerStateAtOpen: pickerState(mainLocationPicker),
      mainPickerDirtyAtOpen: mainLocationPickerDirty,
    };
    button.setAttribute('aria-expanded', 'true');
    positionEditor.hidden = false;
    positionEditor.dataset.activeStepId = String(stepId);
    if (positionEditorTitle) {
      positionEditorTitle.textContent = step.dataset.stepPosition || '';
    }

    const fields = stepFields(step);
    const api = positionPicker.locationGeopointPicker;
    if (!api || typeof api.setCoordinates !== 'function') {
      setPositionStatus('Le sélecteur cartographique est encore en cours de chargement. Réessayez.', 'error');
    } else {
      const hasCoordinates = coordinate(fields.latitude, -90, 90) !== null
        && coordinate(fields.longitude, -180, 180) !== null;
      const fallbackLatitude = coordinate({ value: button.dataset.fallbackLatitude || '' }, -90, 90);
      const fallbackLongitude = coordinate({ value: button.dataset.fallbackLongitude || '' }, -180, 180);
      api.setCoordinates(
        fields.latitude?.value || null,
        fields.longitude?.value || null,
        {
          accuracy: fields.accuracy?.value || null,
          centerLatitude: fallbackLatitude,
          centerLongitude: fallbackLongitude,
          zoom: hasCoordinates ? 17 : (fallbackLatitude !== null && fallbackLongitude !== null ? 15 : 9),
          source: 'route-step-current',
          statusMessage: hasCoordinates
            ? 'Position actuelle chargée. Déplacez le marqueur puis enregistrez cette position.'
            : 'Aucune position enregistrée. Cliquez sur la carte ou relevez votre position GPS.',
        },
      );
      api.invalidateSize?.();
      setPositionStatus(
        step.dataset.gpsInherited === 'true'
          ? 'Cette étape utilise actuellement des coordonnées héritées.'
          : 'Cette étape utilise actuellement des coordonnées personnalisées.',
      );
      if (!primaryPickerIsSafe(activePositionAdjustment)) {
        setPositionStatus(
          'La localisation principale contient une position non enregistrée. Enregistrez-la ou rechargez la page avant d’ajuster cette étape.',
          'error',
        );
      }
    }

    window.requestAnimationFrame(() => {
      const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
      positionEditor.scrollIntoView({ behavior, block: 'start' });
      positionEditor.querySelector('h3')?.focus({ preventScroll: true });
      positionPicker.locationGeopointPicker?.invalidateSize?.();
    });
  };

  const coordinateResponseState = (payload, requestContext) => {
    const item = payload?.step;
    const validPrimaryStepId = Number.isInteger(payload?.primaryLocationStepId)
      && payload.primaryLocationStepId > 0;
    const validLatitude = Number.isFinite(item?.latitude) && item.latitude >= -90 && item.latitude <= 90;
    const validLongitude = Number.isFinite(item?.longitude) && item.longitude >= -180 && item.longitude <= 180;
    const validAccuracy = item?.accuracy === null || (Number.isFinite(item?.accuracy) && item.accuracy >= 0);
    if (payload?.success !== true || typeof payload?.message !== 'string' || !validPrimaryStepId || !item
      || item.id !== requestContext.stepId || item.position !== requestContext.expectedPosition
      || !validLatitude || !validLongitude || !validAccuracy
      || item.coordinatesInherited !== false) {
      return null;
    }

    return {
      id: item.id,
      latitude: String(item.latitude),
      longitude: String(item.longitude),
      accuracy: item.accuracy === null ? '' : String(item.accuracy),
      inherited: false,
      gpsDirty: false,
      message: payload.message,
      position: item.position,
      primaryLocationStepId: payload.primaryLocationStepId,
    };
  };

  const saveAdjustedCoordinates = async (detail) => {
    if (coordinatesSaving || coordinatesStateStale || coordinateUiStateConflict
      || !activePositionAdjustment) {
      return;
    }

    const adjustment = activePositionAdjustment;
    const latitude = Number(detail?.latitude);
    const longitude = Number(detail?.longitude);
    const accuracy = detail?.accuracy === null || detail?.accuracy === ''
      ? null
      : Number(detail?.accuracy);
    if (!Number.isFinite(latitude) || latitude < -90 || latitude > 90
      || !Number.isFinite(longitude) || longitude < -180 || longitude > 180
      || (accuracy !== null && (!Number.isFinite(accuracy) || accuracy < 0))) {
      setPositionStatus('Choisissez une latitude et une longitude valides avant l’enregistrement.', 'error');
      return;
    }
    const selectedCoordinates = Object.freeze({
      latitude: String(detail.latitude),
      longitude: String(detail.longitude),
      accuracy: accuracy === null ? null : String(detail.accuracy),
    });

    coordinatesSaving = true;
    positionEditor?.setAttribute('aria-busy', 'true');
    setCoordinateMutationLocked(true, adjustment, 'coordinate-request');
    let savedState = null;
    let serverCommitConfirmed = false;
    let closeAfterSave = false;
    setPositionStatus('Attente de la confirmation de l’ordre…', 'saving');

    try {
      await waitForOrderSettlement();
      setCoordinateMutationLocked(true, adjustment, 'coordinate-request');
      if (activePositionAdjustment !== adjustment) {
        return;
      }
      if (serverStateUncertain) {
        const error = new Error('L’ordre doit d’abord être reconfirmé avant d’enregistrer cette position GPS.');
        error.coordinateRejected = true;
        throw error;
      }

      const expectedPosition = Number.parseInt(adjustment.step.dataset.stepPosition || '', 10);
      const expectedItem = acknowledgedItem(adjustment.stepId);
      if (!Number.isInteger(expectedPosition) || expectedItem === null) {
        const error = new Error('L’état enregistré de cette étape est indisponible. Rechargez la page avant de réessayer.');
        error.coordinateRejected = true;
        throw error;
      }

      adjustment.isPrimaryStep = adjustment.stepId === primaryStepId();
      if (!primaryPickerIsSafe(adjustment)) {
        const error = new Error('La localisation principale contient une position non enregistrée. Enregistrez-la ou rechargez la page avant de réessayer.');
        error.coordinateRejected = true;
        throw error;
      }

      const requestContext = {
        stepId: adjustment.stepId,
        expectedPosition,
        localStepState: snapshot().find((item) => item.id === adjustment.stepId) || null,
      };
      // Assemble the request explicitly so only the controller's strict
      // contract can be serialized into the JSON body.
      const expectedCoordinates = {};
      expectedCoordinates.latitude = expectedItem.latitude === '' ? null : expectedItem.latitude;
      expectedCoordinates.longitude = expectedItem.longitude === '' ? null : expectedItem.longitude;
      expectedCoordinates.accuracy = expectedItem.accuracy === '' ? null : expectedItem.accuracy;
      expectedCoordinates.coordinatesInherited = expectedItem.inherited;

      const coordinatePayload = {};
      coordinatePayload.latitude = selectedCoordinates.latitude;
      coordinatePayload.longitude = selectedCoordinates.longitude;
      coordinatePayload.expectedPosition = expectedPosition;
      coordinatePayload.expectedCoordinates = expectedCoordinates;
      if (selectedCoordinates.accuracy !== null) {
        coordinatePayload.accuracy = selectedCoordinates.accuracy;
      }
      setPositionStatus('Enregistrement de la position GPS…', 'saving');

      const abortController = new AbortController();
      const timeoutId = window.setTimeout(() => abortController.abort(), 15000);
      let response;
      let payload;
      try {
        response = await fetch(adjustment.button.dataset.coordinatesUrl || '', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': adjustment.button.dataset.coordinatesToken || '',
            'X-Requested-With': 'XMLHttpRequest',
          },
          signal: abortController.signal,
          body: JSON.stringify(coordinatePayload),
        });
        payload = await response.json().catch(() => null);
      } finally {
        window.clearTimeout(timeoutId);
      }

      if (!response.ok) {
        const rejection = new Error(payload?.error || 'La position GPS n’a pas pu être enregistrée.');
        const controlledCodes = [
          'invalid_json',
          'invalid_payload',
          'invalid_precondition',
          'invalid_coordinates',
          'csrf_invalid',
          'not_found',
          'stale_state',
          'write_conflict',
        ];
        rejection.coordinateRejected = [400, 403, 404, 409, 422].includes(response.status)
          && payload?.success === false
          && typeof payload?.error === 'string'
          && controlledCodes.includes(payload?.code);
        rejection.coordinateCode = payload?.code;
        throw rejection;
      }

      savedState = coordinateResponseState(payload, requestContext);
      if (savedState === null) {
        throw new Error('La réponse du serveur ne permet pas de confirmer la position GPS enregistrée.');
      }

      serverCommitConfirmed = true;
      setCoordinatesServerStateUncertain(false, adjustment);
      root.dataset.primaryStepId = String(savedState.primaryLocationStepId);
      adjustment.isPrimaryStep = savedState.primaryLocationStepId === adjustment.stepId;

      const currentLocalStepState = snapshot().find((item) => item.id === adjustment.stepId) || null;
      const localStepUnchanged = gpsStateEquals(currentLocalStepState, requestContext.localStepState);
      if (localStepUnchanged) {
        applyGpsItem(adjustment.step, savedState, 'route-step-map');
      }
      const stepGpsStatus = adjustment.step.querySelector('[data-gps-status]');
      if (stepGpsStatus) {
        stepGpsStatus.textContent = savedState.message;
      }
      if (Array.isArray(acknowledgedState)) {
        acknowledgedState = acknowledgedState.map((item) => (
          item.id === savedState.id
            ? {
              id: savedState.id,
              latitude: savedState.latitude,
              longitude: savedState.longitude,
              accuracy: savedState.accuracy,
              inherited: false,
              gpsDirty: false,
            }
            : item
        ));
      }
      if (localStepUnchanged) {
        updateStepMapLink(adjustment.step, savedState.latitude, savedState.longitude);
      }
      const primaryPickerSynchronized = localStepUnchanged
        && syncPrimaryLocationPicker(adjustment, savedState);
      render();
      if (!primaryPickerSynchronized) {
        setCoordinateUiStateConflict(true, adjustment);
      }
      const confirmationMessage = primaryPickerSynchronized
        ? savedState.message
        : `${savedState.message} Une position locale a changé pendant l’enregistrement et n’a pas été écrasée.`;
      setPositionStatus(confirmationMessage, primaryPickerSynchronized ? 'success' : 'error');
      setStatus(root, savedState.message, 'saved');
      announce(root, savedState.message);
      closeAfterSave = primaryPickerSynchronized;
      // Stable integration hook for a studio route map; the persisted point
      // and the existing GPS widget remain the only coordinate state.
      document.dispatchEvent(new CustomEvent('route-step:coordinates-updated', {
        detail: {
          routeKind: root.dataset.routeKind,
          draftId: Number.parseInt(root.dataset.draftId || '', 10),
          stepId: savedState.id,
          primaryLocationStepId: savedState.primaryLocationStepId,
          position: savedState.position,
          latitude: Number(savedState.latitude),
          longitude: Number(savedState.longitude),
          accuracy: savedState.accuracy === '' ? null : Number(savedState.accuracy),
          coordinatesInherited: false,
        },
      }));
    } catch (error) {
      const staleState = !serverCommitConfirmed
        && ['stale_state', 'not_found'].includes(error?.coordinateCode);
      if (staleState) {
        setCoordinatesStateStale(true, adjustment);
      }
      const outcomeUncertain = !staleState && !serverCommitConfirmed
        && (coordinatesServerStateUncertain || error?.coordinateRejected !== true);
      if (outcomeUncertain) {
        setCoordinatesServerStateUncertain(true, adjustment);
      }
      const message = serverCommitConfirmed
        ? 'La position GPS a été enregistrée, mais l’interface n’a pas pu être entièrement synchronisée. Rechargez la page.'
        : (staleState
          ? (error?.message || 'Cette étape a changé côté serveur. Rechargez la page.')
          : (outcomeUncertain
          ? 'Impossible de confirmer l’état de la position côté serveur. Le point choisi est conservé : réessayez exactement cette position ou rechargez la page.'
          : (error?.message || 'La position GPS n’a pas pu être enregistrée. Réessayez.')));
      setPositionStatus(message, 'error');
      setStatus(root, message, 'error');
      announce(root, message);
    } finally {
      setCoordinateMutationLocked(false, adjustment, 'coordinate-request');
      coordinatesSaving = false;
      positionEditor?.removeAttribute('aria-busy');
      render();
      if (coordinatesServerStateUncertain) {
        setCoordinateMutationLocked(true, adjustment, 'coordinate-uncertain', true);
      }
    }

    if (savedState !== null && closeAfterSave && activePositionAdjustment === adjustment) {
      closePositionEditor(true);
    }
  };

  const sortable = new Sortable(list, {
    animation: 170,
    direction: 'vertical',
    draggable: selector.slot,
    handle: '[data-route-step-drag-handle]',
    ghostClass: 'is-route-step-ghost',
    chosenClass: 'is-route-step-chosen',
    dragClass: 'is-route-step-dragging',
    fallbackClass: 'is-route-step-fallback',
    fallbackTolerance: 5,
    scroll: true,
    scrollSensitivity: 80,
    scrollSpeed: 14,
    onEnd: (event) => {
      if (routeMutationBlockedByCoordinates()) {
        if (acknowledgedState) {
          applyOrder(acknowledgedState);
          syncPrimaryStepIdFromOrder();
        }
        render();
        return;
      }
      if (event.oldIndex === event.newIndex) {
        return;
      }
      const movedStep = event.item.querySelector(selector.step);
      if (movedStep) {
        completeMove(movedStep);
      }
    },
  });

  root.addEventListener('click', (event) => {
    const adjustButton = event.target.closest('[data-route-step-adjust-position]');
    if (adjustButton && root.contains(adjustButton)) {
      if (!routeMutationBlockedByCoordinates() && !adjustButton.disabled) {
        openPositionEditor(adjustButton);
      }
      return;
    }

    const closeButton = event.target.closest('[data-route-step-position-editor-close]');
    if (closeButton && root.contains(closeButton)) {
      if (!routeMutationBlockedByCoordinates() && !closeButton.disabled) {
        closePositionEditor(true);
      }
      return;
    }

    const button = event.target.closest('[data-route-step-up], [data-route-step-down], [data-route-step-copy-previous]');
    if (!button || button.disabled) {
      return;
    }
    if (routeMutationBlockedByCoordinates()) {
      return;
    }

    const step = button.closest(selector.step);
    const slot = step?.closest(selector.slot);
    if (!step || !slot) {
      return;
    }

    if (button.matches('[data-route-step-copy-previous]')) {
      const orderedSteps = steps();
      applyPredecessorCoordinates(step, predecessor(orderedSteps, orderedSteps.indexOf(step)), false);
      render();
      announce(root, `Les coordonnées de l’étape ${stepName(step)} ont été reprises.`);
      return;
    }

    const sibling = button.matches('[data-route-step-up]')
      ? slot.previousElementSibling
      : slot.nextElementSibling;
    if (!sibling?.matches(selector.slot)) {
      return;
    }

    if (button.matches('[data-route-step-up]')) {
      list.insertBefore(slot, sibling);
    } else {
      list.insertBefore(sibling, slot);
    }
    completeMove(step);
    button.focus();
  });

  positionPicker?.addEventListener('location-geopoint-picker:point-validated', (event) => {
    void saveAdjustedCoordinates(event.detail);
  });

  positionEditor?.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !routeMutationBlockedByCoordinates()) {
      event.preventDefault();
      closePositionEditor(true);
    }
  });

  root.addEventListener('input', (event) => {
    const step = event.target.closest(selector.step);
    if (!step) {
      return;
    }

    if (event.target.matches('[data-gps-latitude], [data-gps-longitude], [data-gps-accuracy]')) {
      if (applyingInheritedCoordinates) {
        return;
      }
      markCustomized(step);
      render();
      return;
    }

    if (event.target.matches('[data-route-step-title-input]')) {
      render();
    }
  });

  root.addEventListener('change', (event) => {
    if (!event.target.matches('[data-route-step-type-input]')) {
      return;
    }
    const step = event.target.closest(selector.step);
    if (step) {
      step.dataset.stepType = event.target.value;
      render();
    }
  });

  root.querySelectorAll('[data-route-step-insertion]').forEach((form) => {
    form.addEventListener('submit', () => {
      form.querySelectorAll('[data-route-step-insert-button]').forEach((button) => {
        button.disabled = true;
        button.setAttribute('aria-disabled', 'true');
      });
    });
  });

  document.addEventListener('submit', (event) => {
    if (!isSaving && pendingState === null && !serverStateUncertain
      && !routeMutationBlockedByCoordinates()) {
      return;
    }

    event.preventDefault();
    if (coordinatesSaving) {
      const message = 'Attendez la fin de l’enregistrement de la position GPS avant de valider un formulaire.';
      setStatus(root, message, 'saving');
      announce(root, message);
      return;
    }
    if (coordinatesStateStale) {
      const message = 'Cette étape ou son ordre a changé côté serveur. Rechargez la page avant toute autre modification.';
      setPositionStatus(message, 'error');
      setStatus(root, message, 'error');
      announce(root, message);
      return;
    }
    if (coordinatesServerStateUncertain) {
      const message = 'La position GPS doit être confirmée ou la page rechargée avant de valider un formulaire.';
      setPositionStatus(message, 'error');
      setStatus(root, message, 'error');
      announce(root, message);
      return;
    }
    if (coordinateUiStateConflict) {
      const message = 'La position a été enregistrée, mais des changements locaux doivent être réconciliés. Rechargez la page.';
      setPositionStatus(message, 'error');
      setStatus(root, message, 'error');
      announce(root, message);
      return;
    }
    if (serverStateUncertain && !isSaving && pendingState === null) {
      const message = 'Nouvelle tentative de confirmation de l’ordre avant validation du formulaire…';
      setStatus(root, message, 'saving');
      announce(root, message);
      saveCurrentOrder();
      return;
    }

    const message = 'Attendez la fin de l’enregistrement de l’ordre avant de valider un formulaire.';
    setStatus(root, message, 'saving');
    announce(root, message);
  }, true);

  window.addEventListener('beforeunload', (event) => {
    if (!isSaving && pendingState === null && !serverStateUncertain
      && !routeMutationBlockedByCoordinates()) {
      return;
    }

    event.preventDefault();
    event.returnValue = '';
  });

  acknowledgedState = snapshot();
  render();

  const newStep = root.querySelector('[data-new-route-step="true"]');
  if (newStep) {
    window.requestAnimationFrame(() => {
      newStep.scrollIntoView({ behavior: 'smooth', block: 'center' });
      newStep.querySelector('[data-route-step-title-input]')?.focus({ preventScroll: true });
    });
  }
};

export const initRouteStepOrderings = () => {
  document.querySelectorAll(selector.root).forEach(initRouteStepOrdering);
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initRouteStepOrderings, { once: true });
} else {
  initRouteStepOrderings();
}
