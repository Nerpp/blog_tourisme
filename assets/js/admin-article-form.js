const GALLERY_TYPE = 'gallery';

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
    initCoverChoices(form);
    initFilePreviews(form);
    initDeleteConfirmations(form);
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
