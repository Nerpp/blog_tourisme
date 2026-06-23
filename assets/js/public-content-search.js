const SEARCH_SELECTOR = '[data-public-content-search]';
const OPTION_SELECTOR = '[data-public-search-option]';
const DEFAULT_MIN_CHARS = 2;
const DEBOUNCE_DELAY = 180;

const asSuggestionList = (value) => {
  if (!value || !Array.isArray(value.suggestions)) {
    return [];
  }

  return value.suggestions.filter((suggestion) => (
    suggestion
    && typeof suggestion.title === 'string'
    && typeof suggestion.url === 'string'
    && suggestion.title.trim() !== ''
    && suggestion.url.trim() !== ''
  ));
};

const createSuggestionLink = (suggestion, index, inputId) => {
  const link = document.createElement('a');
  link.href = suggestion.url;
  link.id = `${inputId || 'public-search'}-option-${index}`;
  link.className = 'article-index-search__suggestion';
  link.setAttribute('role', 'option');
  link.setAttribute('aria-selected', 'false');
  link.tabIndex = -1;
  link.dataset.publicSearchOption = '';

  const title = document.createElement('strong');
  title.textContent = suggestion.title;
  link.append(title);

  const meta = document.createElement('span');
  const metaParts = [suggestion.type, suggestion.meta]
    .filter((part) => typeof part === 'string' && part.trim() !== '');
  meta.textContent = metaParts.join(' · ');
  link.append(meta);

  return link;
};

const initSearch = (form) => {
  const input = form.querySelector('[data-public-search-input]');
  const suggestions = form.querySelector('[data-public-search-suggestions]');
  const status = form.querySelector('[data-public-search-status]');
  const endpoint = form.dataset.autocompleteUrl;
  const minChars = Number.parseInt(form.dataset.minChars || '', 10) || DEFAULT_MIN_CHARS;

  if (!input || !suggestions || !endpoint) {
    return;
  }

  let abortController = null;
  let debounceTimer = null;
  let activeIndex = -1;

  const options = () => Array.from(suggestions.querySelectorAll(OPTION_SELECTOR));

  const setStatus = (message) => {
    if (status) {
      status.textContent = message;
    }
  };

  const setExpanded = (expanded) => {
    input.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  };

  const setActiveOption = (nextIndex) => {
    const currentOptions = options();
    activeIndex = currentOptions.length === 0 ? -1 : nextIndex;

    currentOptions.forEach((option, index) => {
      const isActive = index === activeIndex;
      option.classList.toggle('is-active', isActive);
      option.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    if (activeIndex >= 0 && currentOptions[activeIndex]) {
      input.setAttribute('aria-activedescendant', currentOptions[activeIndex].id);
    } else {
      input.removeAttribute('aria-activedescendant');
    }
  };

  const closeSuggestions = () => {
    suggestions.hidden = true;
    suggestions.replaceChildren();
    setExpanded(false);
    setActiveOption(-1);
    setStatus('');
  };

  const renderSuggestions = (items) => {
    suggestions.replaceChildren();

    if (items.length === 0) {
      closeSuggestions();
      return;
    }

    items.forEach((suggestion, index) => {
      suggestions.append(createSuggestionLink(suggestion, index, input.id));
    });

    suggestions.hidden = false;
    setExpanded(true);
    setActiveOption(-1);
    setStatus(`${items.length} suggestion${items.length > 1 ? 's' : ''} disponible${items.length > 1 ? 's' : ''}.`);
  };

  const abortPendingRequest = () => {
    if (abortController) {
      abortController.abort();
      abortController = null;
    }
  };

  const fetchSuggestions = async (query) => {
    abortPendingRequest();
    const controller = new AbortController();
    abortController = controller;

    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set('q', query);

    try {
      const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      });

      if (!response.ok || input.value.trim() !== query) {
        return;
      }

      renderSuggestions(asSuggestionList(await response.json()));
    } catch (error) {
      if (!(error instanceof DOMException && error.name === 'AbortError')) {
        closeSuggestions();
      }
    } finally {
      if (abortController === controller) {
        abortController = null;
      }
    }
  };

  const queueSuggestions = () => {
    const query = input.value.trim();
    window.clearTimeout(debounceTimer);
    setActiveOption(-1);

    if (query.length < minChars) {
      abortPendingRequest();
      closeSuggestions();
      return;
    }

    debounceTimer = window.setTimeout(() => {
      fetchSuggestions(query);
    }, DEBOUNCE_DELAY);
  };

  input.addEventListener('input', queueSuggestions);

  input.addEventListener('keydown', (event) => {
    const currentOptions = options();
    const isEscape = event.key === 'Escape' || event.key === 'Esc' || event.code === 'Escape' || event.keyCode === 27;
    const isArrowDown = event.key === 'ArrowDown' || event.key === 'Down' || event.code === 'ArrowDown' || event.keyCode === 40;
    const isArrowUp = event.key === 'ArrowUp' || event.key === 'Up' || event.code === 'ArrowUp' || event.keyCode === 38;

    if (isEscape) {
      closeSuggestions();
      return;
    }

    if (suggestions.hidden || currentOptions.length === 0) {
      return;
    }

    if (isArrowDown) {
      event.preventDefault();
      setActiveOption((activeIndex + 1) % currentOptions.length);
      return;
    }

    if (isArrowUp) {
      event.preventDefault();
      setActiveOption(activeIndex <= 0 ? currentOptions.length - 1 : activeIndex - 1);
      return;
    }

    if ((event.key === 'Enter' || event.code === 'Enter' || event.keyCode === 13) && activeIndex >= 0 && currentOptions[activeIndex]) {
      event.preventDefault();
      window.location.assign(currentOptions[activeIndex].href);
    }
  });

  input.addEventListener('focus', () => {
    if (input.value.trim().length >= minChars && suggestions.hidden) {
      queueSuggestions();
    }
  });

  document.addEventListener('click', (event) => {
    if (!form.contains(event.target)) {
      closeSuggestions();
    }
  });
};

export function initPublicContentSearch() {
  document.querySelectorAll(SEARCH_SELECTOR).forEach(initSearch);
}
