const GALLERY_TYPE = 'gallery';

const clearAutocomplete = (autocomplete) => {
    const hiddenInput = autocomplete.querySelector('[data-admin-autocomplete-value]');
    const searchInput = autocomplete.querySelector('[data-admin-autocomplete-input]');
    const selection = autocomplete.querySelector('[data-admin-autocomplete-selection]');
    const results = autocomplete.querySelector('[data-admin-autocomplete-results]');

    if (hiddenInput instanceof HTMLInputElement) {
        hiddenInput.value = '';
    }
    if (searchInput instanceof HTMLInputElement) {
        searchInput.value = '';
    }
    if (selection instanceof HTMLElement) {
        selection.hidden = true;
        selection.textContent = '';
    }
    if (results instanceof HTMLElement) {
        results.hidden = true;
        results.replaceChildren();
    }
};

const initLinkedContentFields = (form) => {
    const typeSelect = form.querySelector('[data-article-link-type]');
    const panels = Array.from(form.querySelectorAll('[data-article-link-panel]'));
    const roleField = form.querySelector('[data-article-link-role]');

    if (!(typeSelect instanceof HTMLSelectElement) || panels.length === 0) {
        return;
    }

    form.querySelectorAll('[data-admin-autocomplete]').forEach((autocomplete) => {
        const hiddenInput = autocomplete.querySelector('[data-admin-autocomplete-value]');
        const searchInput = autocomplete.querySelector('[data-admin-autocomplete-input]');
        const results = autocomplete.querySelector('[data-admin-autocomplete-results]');
        const selection = autocomplete.querySelector('[data-admin-autocomplete-selection]');
        const options = Array.from(autocomplete.querySelectorAll('[data-admin-autocomplete-option]'));
        const emptyLabel = autocomplete.dataset.emptyLabel || 'Aucun résultat';

        if (
            !(hiddenInput instanceof HTMLInputElement)
            || !(searchInput instanceof HTMLInputElement)
            || !(results instanceof HTMLElement)
            || !(selection instanceof HTMLElement)
        ) {
            return;
        }

        const selectOption = (option) => {
            hiddenInput.value = option.dataset.id || '';
            searchInput.value = '';
            selection.hidden = false;
            selection.textContent = option.dataset.label || '';
            results.hidden = true;
            results.replaceChildren();
        };

        const renderResults = () => {
            const query = searchInput.value.trim().toLowerCase();
            results.replaceChildren();

            if (query === '') {
                results.hidden = true;
                return;
            }

            const matches = options
                .filter((option) => (option.dataset.search || '').includes(query))
                .slice(0, 10);

            if (matches.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'admin-autocomplete__empty';
                empty.textContent = emptyLabel;
                results.append(empty);
                results.hidden = false;
                return;
            }

            matches.forEach((option) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'admin-autocomplete__result';
                button.textContent = option.dataset.label || '';
                button.addEventListener('click', () => selectOption(option));
                results.append(button);
            });

            results.hidden = false;
        };

        const preselected = options.find((option) => option.dataset.id === hiddenInput.value);
        if (preselected) {
            selectOption(preselected);
        }

        searchInput.addEventListener('input', () => {
            hiddenInput.value = '';
            selection.hidden = true;
            selection.textContent = '';
            renderResults();
        });
        searchInput.addEventListener('focus', renderResults);
    });

    const refreshPanels = () => {
        panels.forEach((panel) => {
            const isActive = panel.dataset.articleLinkPanel === typeSelect.value;
            panel.hidden = !isActive;
            panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
            panel.querySelectorAll('input, select, textarea, button').forEach((control) => {
                control.disabled = !isActive;
            });

            if (!isActive) {
                panel.querySelectorAll('[data-admin-autocomplete]').forEach(clearAutocomplete);
            }
        });

        if (roleField instanceof HTMLElement) {
            const hasLinkedContent = typeSelect.value !== 'none';
            roleField.hidden = !hasLinkedContent;
            roleField.setAttribute('aria-hidden', hasLinkedContent ? 'false' : 'true');
            roleField.querySelectorAll('select').forEach((select) => {
                select.disabled = !hasLinkedContent;
            });
        }
    };

    typeSelect.addEventListener('change', refreshPanels);
    refreshPanels();
};

const formatFileSize = (size) => {
    if (size < 1024 * 1024) {
        return `${Math.max(1, Math.round(size / 1024))} Ko`;
    }

    return `${(size / (1024 * 1024)).toFixed(1)} Mo`;
};

const replaceInputFiles = (input, files) => {
    if (typeof DataTransfer === 'undefined') {
        if (files.length === 0) {
            input.value = '';
        }

        return;
    }

    const transfer = new DataTransfer();
    files.forEach((file) => transfer.items.add(file));
    input.files = transfer.files;
};

const createPreviewCard = (file, objectUrl, actions) => {
    const card = document.createElement('article');
    card.className = 'article-upload-card';

    const image = document.createElement('img');
    image.src = objectUrl;
    image.alt = `Aperçu de ${file.name}`;
    image.className = 'article-upload-card__image';
    image.loading = 'lazy';
    image.decoding = 'async';
    card.append(image);

    const body = document.createElement('div');
    body.className = 'article-upload-card__body';

    const title = document.createElement('strong');
    title.textContent = file.name;
    body.append(title);

    const meta = document.createElement('span');
    meta.className = 'admin-muted';
    meta.textContent = formatFileSize(file.size);
    body.append(meta);

    const actionRow = document.createElement('div');
    actionRow.className = 'article-upload-card__actions';

    actions.forEach((action) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = action.secondary ? 'admin-button admin-button--secondary' : 'admin-button';
        button.textContent = action.label;
        button.disabled = action.disabled === true;
        button.addEventListener('click', action.onClick);
        actionRow.append(button);
    });

    body.append(actionRow);
    card.append(body);

    return card;
};

const initCoverChoices = (form) => {
    const choices = Array.from(form.querySelectorAll('[data-article-cover-choice]'));
    let lastExistingChoice = choices.find((choice) => choice.checked) ?? null;

    const refreshCards = () => {
        choices.forEach((choice) => {
            const card = choice.closest('[data-main-media-card]');
            if (!card) {
                return;
            }

            const isMain = choice.checked;
            card.classList.toggle('is-main-media', isMain);
            card.dataset.mainMediaState = isMain ? 'main' : 'gallery';

            const badge = card.querySelector('[data-main-media-badge]');
            if (badge) {
                badge.textContent = isMain ? 'Image principale' : 'Galerie générale';
            }
        });
    };

    choices.forEach((choice) => {
        choice.addEventListener('change', () => {
            if (choice.checked) {
                lastExistingChoice = choice;
                form.dispatchEvent(new CustomEvent('article:existing-cover-selected'));
            }

            refreshCards();
        });
    });

    form.addEventListener('article:new-cover-selected', () => {
        const selectedChoice = choices.find((choice) => choice.checked);
        if (selectedChoice) {
            lastExistingChoice = selectedChoice;
        }
        choices.forEach((choice) => {
            choice.checked = false;
        });
        refreshCards();
    });

    form.addEventListener('article:new-cover-cleared', () => {
        if (lastExistingChoice) {
            lastExistingChoice.checked = true;
        }
        refreshCards();
    });

    refreshCards();
};

const initFilePreviews = (form) => {
    const createStore = () => {
        const input = form.querySelector(`[data-article-file-input="${GALLERY_TYPE}"]`);
        const preview = form.querySelector(`[data-article-file-preview="${GALLERY_TYPE}"]`);
        const newCoverIndexInput = form.querySelector('[data-article-new-cover-index]');

        if (!(input instanceof HTMLInputElement) || !preview || !(newCoverIndexInput instanceof HTMLInputElement)) {
            return null;
        }

        let files = [];
        let objectUrls = [];
        let mainIndex = null;

        const cleanupUrls = () => {
            objectUrls.forEach((url) => URL.revokeObjectURL(url));
            objectUrls = [];
        };

        const setFiles = (nextFiles) => {
            const hadMainImage = mainIndex !== null;
            cleanupUrls();
            files = nextFiles;
            mainIndex = null;
            replaceInputFiles(input, files);
            render();
            if (hadMainImage) {
                form.dispatchEvent(new CustomEvent('article:new-cover-cleared'));
            }
        };

        const removeAt = (index) => {
            const removedMainImage = mainIndex === index;
            cleanupUrls();
            files = files.filter((_, fileIndex) => fileIndex !== index);
            if (mainIndex !== null) {
                mainIndex = removedMainImage ? null : mainIndex - (index < mainIndex ? 1 : 0);
            }
            replaceInputFiles(input, files);
            render();
            if (removedMainImage) {
                form.dispatchEvent(new CustomEvent('article:new-cover-cleared'));
            }
        };

        const setMainImage = (index) => {
            mainIndex = index;
            render();
            form.dispatchEvent(new CustomEvent('article:new-cover-selected'));
        };

        const render = () => {
            preview.replaceChildren();
            newCoverIndexInput.value = mainIndex === null ? '' : String(mainIndex);

            if (files.length === 0) {
                preview.hidden = true;
                return;
            }

            preview.hidden = false;
            files.forEach((file, index) => {
                const objectUrl = URL.createObjectURL(file);
                objectUrls.push(objectUrl);

                const actions = [
                    {
                        label: mainIndex === index ? 'Image principale' : 'Définir comme image principale',
                        secondary: mainIndex === index,
                        disabled: mainIndex === index,
                        onClick: () => setMainImage(index),
                    },
                    {
                        label: 'Supprimer l’image',
                        secondary: true,
                        onClick: () => removeAt(index),
                    },
                ];

                preview.append(createPreviewCard(file, objectUrl, actions));
            });
        };

        input.addEventListener('change', () => {
            setFiles(Array.from(input.files ?? []));
        });

        preview.hidden = true;

        form.addEventListener('article:existing-cover-selected', () => {
            if (mainIndex === null) {
                return;
            }
            mainIndex = null;
            render();
        });

        return { setFiles };
    };

    createStore();
};

const initDeleteConfirmations = (form) => {
    form.querySelectorAll('[data-article-delete-media]').forEach((input) => {
        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        input.addEventListener('change', () => {
            if (!input.checked) {
                return;
            }

            const message = input.dataset.articleDeleteMessage
                || 'Supprimer définitivement cette image de l’article ?\nElle sera retirée du contenu et supprimée du serveur si elle n’est utilisée nulle part ailleurs.';
            if (!window.confirm(message)) {
                input.checked = false;
            }
        });
    });
};

const fallbackCopyText = (text) => {
    const activeElement = document.activeElement;
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    textarea.style.top = '0';
    document.body.append(textarea);
    textarea.select();

    try {
        if (!document.execCommand('copy')) {
            throw new Error('Copy command was refused.');
        }
    } finally {
        textarea.remove();
        if (activeElement && typeof activeElement.focus === 'function') {
            activeElement.focus();
        }
    }
};

const copyText = async (text) => {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        try {
            await navigator.clipboard.writeText(text);
            return;
        } catch (error) {
            // The fallback below covers blocked Clipboard API permissions.
        }
    }

    fallbackCopyText(text);
};

const initMediaCodeCopy = (form) => {
    const status = form.querySelector('[data-article-copy-status]');
    const resetTimers = new WeakMap();

    form.querySelectorAll('[data-article-copy-media-code]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        const defaultLabel = button.textContent?.trim() || 'Copier le code';

        button.addEventListener('click', async (event) => {
            event.preventDefault();

            const code = button.dataset.articleCopyMediaCode || '';
            if (code === '') {
                return;
            }

            window.clearTimeout(resetTimers.get(button));

            try {
                await copyText(code);
                button.textContent = 'Code copié';
                if (status) {
                    status.textContent = `Code copié : ${code}`;
                }
            } catch (error) {
                button.textContent = 'Copie impossible';
                if (status) {
                    status.textContent = `Copie impossible. Le code à copier est ${code}.`;
                }
            }

            resetTimers.set(button, window.setTimeout(() => {
                button.textContent = defaultLabel;
                if (status) {
                    status.textContent = '';
                }
            }, 1800));
        });
    });
};

const initSubmitLock = (form) => {
    let isSubmitting = false;
    const savingLabel = form.dataset.articleSavingLabel || 'Enregistrement en cours…';
    const status = form.querySelector('[data-article-submit-status]');
    const submitButtons = Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]'));

    form.addEventListener('submit', (event) => {
        if (isSubmitting) {
            event.preventDefault();
            return;
        }

        if (!form.checkValidity()) {
            return;
        }

        isSubmitting = true;
        form.setAttribute('aria-busy', 'true');

        submitButtons.forEach((button) => {
            button.dataset.originalLabel = button.textContent?.trim() || '';
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');

            const label = button.querySelector('[data-article-submit-label]');
            if (label) {
                label.textContent = savingLabel;
            } else {
                button.textContent = savingLabel;
            }
        });

        if (status) {
            status.textContent = savingLabel;
        }
    });
};

const initArticleForm = (form) => {
    initLinkedContentFields(form);
    initCoverChoices(form);
    initFilePreviews(form);
    initDeleteConfirmations(form);
    initMediaCodeCopy(form);
    initSubmitLock(form);
};

const init = () => {
    document.querySelectorAll('[data-article-admin-form]').forEach((form) => {
        if (form instanceof HTMLFormElement) {
            initArticleForm(form);
        }
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}
