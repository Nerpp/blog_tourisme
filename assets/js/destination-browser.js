const normalizeSearchValue = (value) => value
  .toLocaleLowerCase('fr-FR')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .trim();

const getDirectChildNodes = (node) => {
  const branch = Array.from(node.children).find((child) => child.matches('.destination-branch'));

  if (!branch) {
    return [];
  }

  const childTree = Array.from(branch.children).find((child) => child.matches('.destination-tree'));

  if (!childTree) {
    return [];
  }

  return Array.from(childTree.children).filter((child) => child.matches('[data-destination-node]'));
};

const getDirectBranch = (node) => Array
  .from(node.children)
  .find((child) => child.matches('.destination-branch'));

const closeAllBranches = (browser) => {
  browser.querySelectorAll('details.destination-branch').forEach((branch) => {
    branch.open = false;
  });
};

const resetTree = (browser, nodes, emptyState) => {
  nodes.forEach((node) => {
    node.classList.remove('is-hidden', 'is-match');
  });

  closeAllBranches(browser);

  if (emptyState) {
    emptyState.hidden = true;
  }
};

const filterTree = (browser, query, rootNodes, emptyState) => {
  const filterNode = (node) => {
    const searchText = node.dataset.destinationSearchText || '';
    const ownMatch = searchText.includes(query);
    const children = getDirectChildNodes(node);
    let childMatch = false;
    const branch = getDirectBranch(node);

    children.forEach((child) => {
      if (filterNode(child)) {
        childMatch = true;
      }
    });

    const isVisible = ownMatch || childMatch;

    node.classList.toggle('is-hidden', !isVisible);
    node.classList.toggle('is-match', ownMatch);

    if (branch) {
      branch.open = childMatch;
    }

    return isVisible;
  };

  let hasVisibleNode = false;

  rootNodes.forEach((node) => {
    if (filterNode(node)) {
      hasVisibleNode = true;
    }
  });

  if (emptyState) {
    emptyState.hidden = hasVisibleNode;
  }

  if (!hasVisibleNode) {
    closeAllBranches(browser);
  }
};

export function initDestinationBrowser() {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDestinationBrowser, { once: true });
    return;
  }

  document.querySelectorAll('[data-destination-browser]').forEach((browser) => {
    if (browser.dataset.destinationBrowserReady === 'true') {
      return;
    }

    browser.dataset.destinationBrowserReady = 'true';

    const searchInput = browser.querySelector('[data-destination-search]');
    const openAllButton = browser.querySelector('[data-destination-open-all]');
    const closeAllButton = browser.querySelector('[data-destination-close-all]');
    const emptyState = browser.querySelector('[data-destination-empty]');
    const rootTree = browser.querySelector('.destinations-panel > .destination-tree');
    const nodes = Array.from(browser.querySelectorAll('[data-destination-node]'));
    const rootNodes = rootTree
      ? Array.from(rootTree.children).filter((child) => child.matches('[data-destination-node]'))
      : [];

    nodes.forEach((node) => {
      node.dataset.destinationSearchText = normalizeSearchValue(`${node.dataset.destinationName || ''} ${node.dataset.destinationType || ''}`);
    });

    browser.querySelectorAll('[data-destination-link], summary a').forEach((link) => {
      link.addEventListener('click', (event) => {
        event.stopPropagation();
      });
    });

    resetTree(browser, nodes, emptyState);

    openAllButton?.addEventListener('click', () => {
      browser.querySelectorAll('details.destination-branch').forEach((branch) => {
        branch.open = true;
      });
    });

    closeAllButton?.addEventListener('click', () => {
      closeAllBranches(browser);
    });

    searchInput?.addEventListener('input', () => {
      const query = normalizeSearchValue(searchInput.value);

      if (query === '') {
        resetTree(browser, nodes, emptyState);
        return;
      }

      filterTree(browser, query, rootNodes, emptyState);
    });
  });
}
