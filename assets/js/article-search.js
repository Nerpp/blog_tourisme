const normalize = (value) => value
  .toString()
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .toLowerCase()
  .trim();

export function initArticleSearch() {
  const root = document.querySelector('.js-article-search');
  const cards = Array.from(document.querySelectorAll('[data-article-card]'));

  if (!root || cards.length === 0) {
    return;
  }

  const input = root.querySelector('.js-article-search-input');
  const resetButton = root.querySelector('.js-article-search-reset');
  const suggestions = root.querySelector('.js-article-search-suggestions');
  const emptyMessage = document.querySelector('.js-article-search-empty');
  const count = document.querySelector('.js-article-search-count');
  const quickFilters = Array.from(root.querySelectorAll('.js-article-query'));

  if (!input || !suggestions) {
    return;
  }

  const items = cards.map((card) => ({
    card,
    title: card.dataset.title || '',
    url: card.dataset.url || '#',
    meta: card.dataset.meta || '',
    linked: card.dataset.linked || '',
    search: normalize(card.dataset.search || ''),
  }));

  const updateCount = (visibleCount) => {
    if (!count) {
      return;
    }

    count.textContent = `${visibleCount} article${visibleCount > 1 ? 's' : ''}`;
  };

  const closeSuggestions = () => {
    suggestions.hidden = true;
    suggestions.innerHTML = '';
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
      link.className = 'article-index-search__suggestion';
      link.setAttribute('role', 'option');

      const title = document.createElement('strong');
      title.textContent = item.title;
      link.appendChild(title);

      const meta = document.createElement('span');
      meta.textContent = item.linked
        ? `${item.meta} · lié à ${item.linked}`
        : item.meta;
      link.appendChild(meta);

      suggestions.appendChild(link);
    });

    suggestions.hidden = false;
  };

  const filter = () => {
    const query = normalize(input.value);
    const hasQuery = query.length > 0;
    const matches = [];

    items.forEach((item) => {
      const visible = !hasQuery || item.search.includes(query);
      item.card.hidden = !visible;

      if (visible) {
        matches.push(item);
      }
    });

    if (resetButton) {
      resetButton.hidden = !hasQuery;
    }

    if (emptyMessage) {
      emptyMessage.hidden = matches.length > 0;
    }

    updateCount(matches.length);

    if (hasQuery) {
      renderSuggestions(matches);
    } else {
      closeSuggestions();
    }
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
      input.focus();
      filter();
    });
  }

  quickFilters.forEach((button) => {
    button.addEventListener('click', () => {
      input.value = button.dataset.articleQuery || '';
      input.focus();
      filter();
    });
  });

  document.addEventListener('click', (event) => {
    if (!root.contains(event.target)) {
      closeSuggestions();
    }
  });
}
