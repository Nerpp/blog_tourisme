const normalize = (value) => value
  .toString()
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .toLowerCase()
  .trim();

export function initDestinationShowSearch() {
  const root = document.querySelector('.js-destination-detail-search');
  const cards = Array.from(document.querySelectorAll('[data-destination-show-card]'));

  if (!root) {
    return;
  }

  const input = root.querySelector('.js-destination-detail-search-input');
  const resetButton = root.querySelector('.js-destination-detail-search-reset');
  const suggestions = root.querySelector('.js-destination-detail-search-suggestions');
  const emptyMessage = root.querySelector('.js-destination-detail-empty');
  const filterButtons = Array.from(root.querySelectorAll('[data-destination-filter]'));
  const departmentButtons = Array.from(root.querySelectorAll('[data-destination-department-filter]'));
  const departmentCardButtons = Array.from(document.querySelectorAll('[data-destination-department-card]'));
  const departmentResetButtons = Array.from(document.querySelectorAll('[data-destination-department-reset]'));
  const departmentPanel = root.querySelector('.js-destination-department-panel');
  const departmentPanelTitle = root.querySelector('[data-destination-department-panel-title]');
  const departmentPanelLink = root.querySelector('[data-destination-department-panel-link]');
  const dynamicSectionTitle = document.querySelector('[data-destination-original-title]');
  const pathLinks = Array.from(root.querySelectorAll('[data-destination-search-path-link]'));

  if (!input || !suggestions) {
    return;
  }

  const initialParams = new URLSearchParams(window.location.search);
  const requestedFilter = initialParams.get('type') || 'all';
  const requestedDepartment = initialParams.get('department') || '';
  let currentFilter = filterButtons.some((button) => button.dataset.destinationFilter === requestedFilter)
    ? requestedFilter
    : 'all';
  let currentDepartment = departmentButtons.some((button) => button.dataset.destinationDepartmentFilter === requestedDepartment)
    ? requestedDepartment
    : '';

  input.value = initialParams.get('q') || '';

  const items = cards.map((card) => ({
    card,
    type: card.dataset.type || 'other',
    title: card.dataset.title || '',
    url: card.dataset.url || '#',
    meta: card.dataset.meta || '',
    context: card.dataset.context || '',
    departmentId: card.dataset.departmentId || '',
    departmentName: card.dataset.departmentName || '',
    search: normalize(`${card.dataset.search || ''} ${card.dataset.title || ''} ${card.dataset.meta || ''} ${card.dataset.context || ''} ${card.dataset.departmentName || ''}`),
  }));

  const closeSuggestions = () => {
    suggestions.hidden = true;
    suggestions.innerHTML = '';
  };

  const updateUrlState = () => {
    const pageUrl = new URL(window.location.href);
    const query = input.value.trim();

    if (query) {
      pageUrl.searchParams.set('q', query);
    } else {
      pageUrl.searchParams.delete('q');
    }

    if (currentFilter !== 'all') {
      pageUrl.searchParams.set('type', currentFilter);
    } else {
      pageUrl.searchParams.delete('type');
    }

    if (currentDepartment !== '') {
      pageUrl.searchParams.set('department', currentDepartment);
    } else {
      pageUrl.searchParams.delete('department');
    }

    window.history.replaceState({}, '', pageUrl);

    pathLinks.forEach((link) => {
      const linkUrl = new URL(link.href, window.location.origin);

      if (query) {
        linkUrl.searchParams.set('q', query);
      } else {
        linkUrl.searchParams.delete('q');
      }

      if (currentFilter !== 'all' && !link.hasAttribute('data-destination-search-path-root')) {
        linkUrl.searchParams.set('type', currentFilter);
      } else {
        linkUrl.searchParams.delete('type');
      }

      linkUrl.searchParams.delete('department');
      link.href = `${linkUrl.pathname}${linkUrl.search}${linkUrl.hash}`;
    });
  };

  const renderSuggestions = (matches) => {
    suggestions.innerHTML = '';

    if (matches.length === 0) {
      closeSuggestions();
      return;
    }

    matches.slice(0, 8).forEach((item) => {
      const link = document.createElement('a');
      link.href = item.url;
      link.className = 'destination-detail-search__suggestion';
      link.setAttribute('role', 'option');

      const title = document.createElement('strong');
      title.textContent = item.title;
      link.appendChild(title);

      const meta = document.createElement('span');
      meta.textContent = item.context
        ? `${item.meta} · ${item.context}`
        : item.meta;
      link.appendChild(meta);

      suggestions.appendChild(link);
    });

    suggestions.hidden = false;
  };

  const updateSections = () => {
    document.querySelectorAll('[data-destination-section]').forEach((section) => {
      const visibleCards = section.querySelectorAll('[data-destination-show-card]:not([hidden])');
      const hasCards = visibleCards.length > 0;
      section.hidden = !hasCards && (normalize(input.value).length > 0 || currentFilter !== 'all' || currentDepartment !== '');
    });
  };

  const selectedDepartmentButton = () => departmentButtons.find((button) => (button.dataset.destinationDepartmentFilter || '') === currentDepartment);

  const updateDepartmentPanel = () => {
    const button = selectedDepartmentButton();
    const departmentName = button?.dataset.destinationDepartmentName || '';
    const departmentUrl = button?.dataset.destinationDepartmentUrl || '#';

    departmentButtons.forEach((candidate) => {
      candidate.classList.toggle('is-active', (candidate.dataset.destinationDepartmentFilter || '') === currentDepartment);
    });

    if (departmentPanel) {
      departmentPanel.hidden = currentDepartment === '';
    }

    if (departmentPanelTitle && departmentName) {
      departmentPanelTitle.textContent = `À découvrir dans ${departmentName}`;
    }

    if (departmentPanelLink && departmentName) {
      departmentPanelLink.href = departmentUrl;
      departmentPanelLink.textContent = 'Voir la page complète du département';
    }

    if (dynamicSectionTitle) {
      dynamicSectionTitle.textContent = departmentName
        ? `À découvrir dans ${departmentName}`
        : dynamicSectionTitle.dataset.destinationOriginalTitle;
    }
  };

  const selectDepartment = (departmentId) => {
    currentDepartment = departmentId || '';
    updateDepartmentPanel();
    filter();
  };

  const filter = () => {
    const query = normalize(input.value);
    const hasQuery = query.length > 0;
    const matches = [];

    items.forEach((item) => {
      const typeMatches = currentFilter === 'all' || item.type === currentFilter;
      const departmentMatches = currentDepartment === '' || item.departmentId === currentDepartment;
      const queryMatches = !hasQuery || item.search.includes(query);
      const visible = typeMatches && departmentMatches && queryMatches;
      item.card.hidden = !visible;

      if (visible) {
        matches.push(item);
      }
    });

    if (resetButton) {
      resetButton.hidden = !hasQuery && currentFilter === 'all' && currentDepartment === '';
    }

    if (emptyMessage) {
      emptyMessage.hidden = matches.length > 0;
    }

    if (hasQuery) {
      renderSuggestions(matches);
    } else {
      closeSuggestions();
    }

    updateUrlState();
    updateSections();
  };

  input.addEventListener('input', filter);

  input.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeSuggestions();
      return;
    }

    if (event.key === 'Enter' && !suggestions.hidden) {
      const firstSuggestion = suggestions.querySelector('a');
      if (firstSuggestion) {
        event.preventDefault();
        firstSuggestion.click();
      }
    }
  });

  if (resetButton) {
    resetButton.addEventListener('click', () => {
      input.value = '';
      currentFilter = 'all';
      currentDepartment = '';
      filterButtons.forEach((button) => {
        button.classList.toggle('is-active', button.dataset.destinationFilter === 'all');
      });
      updateDepartmentPanel();
      input.focus();
      filter();
    });
  }

  filterButtons.forEach((button) => {
    button.addEventListener('click', () => {
      currentFilter = button.dataset.destinationFilter || 'all';
      filterButtons.forEach((candidate) => {
        candidate.classList.toggle('is-active', candidate === button);
      });
      filter();
    });
  });

  departmentButtons.forEach((button) => {
    button.addEventListener('click', () => {
      selectDepartment(button.dataset.destinationDepartmentFilter || '');
    });
  });

  departmentCardButtons.forEach((button) => {
    button.addEventListener('click', () => {
      selectDepartment(button.dataset.destinationDepartmentCard || '');
      root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  departmentResetButtons.forEach((button) => {
    button.addEventListener('click', () => {
      selectDepartment('');
      input.focus();
    });
  });

  document.addEventListener('click', (event) => {
    if (!root.contains(event.target)) {
      closeSuggestions();
    }
  });

  filterButtons.forEach((button) => {
    button.classList.toggle('is-active', button.dataset.destinationFilter === currentFilter);
  });
  updateDepartmentPanel();
  filter();
}
