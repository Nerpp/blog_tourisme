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

  let applyingInheritedCoordinates = false;
  let isSaving = false;
  let pendingState = null;
  let acknowledgedState = null;
  let serverStateUncertain = false;

  const slots = () => directChildren(list, selector.slot);
  const steps = () => slots().map(stepInSlot).filter(Boolean);

  const stepFields = (step) => ({
    latitude: step.querySelector('[data-gps-latitude]'),
    longitude: step.querySelector('[data-gps-longitude]'),
    accuracy: step.querySelector('[data-gps-accuracy]'),
    inherited: step.querySelector('[data-route-step-inherited-input]'),
  });

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
    });

    const firstLabel = firstInsertion?.querySelector('[data-route-step-insert-label]');
    if (firstLabel) {
      firstLabel.textContent = orderedSteps.length === 0 ? 'Ajouter la première étape' : 'Ajouter au début';
    }

    updateMediaPointLabels(orderedSteps);
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

  const setMutationFormsLocked = (locked) => {
    root.querySelectorAll('form button[type="submit"]').forEach((button) => {
      if (locked) {
        button.dataset.routeStepWasDisabled = button.disabled ? 'true' : 'false';
        button.disabled = true;
        button.setAttribute('aria-disabled', 'true');
      } else {
        button.disabled = button.dataset.routeStepWasDisabled === 'true';
        button.removeAttribute('data-route-step-was-disabled');
        if (!button.disabled) {
          button.removeAttribute('aria-disabled');
        }
      }
    });
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
    }
  };

  const saveCurrentOrder = () => {
    pendingState = snapshot();
    void drainSaveQueue();
  };

  const completeMove = (movedStep) => {
    refreshInheritedCoordinates();
    render();
    const position = steps().indexOf(movedStep) + 1;
    announce(root, `L’étape ${stepName(movedStep)} est maintenant en position ${position}.`);
    saveCurrentOrder();
  };

  new Sortable(list, {
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
    const button = event.target.closest('[data-route-step-up], [data-route-step-down], [data-route-step-copy-previous]');
    if (!button || button.disabled) {
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
    if (!isSaving && pendingState === null && !serverStateUncertain) {
      return;
    }

    event.preventDefault();
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
    if (!isSaving && pendingState === null && !serverStateUncertain) {
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
