const MAIN_VALUE = 'main';
const GALLERY_VALUE = 'gallery';

const setCardState = (card, isMain) => {
    card.classList.toggle('is-main-media', isMain);
    card.dataset.mainMediaState = isMain ? MAIN_VALUE : GALLERY_VALUE;

    const badge = card.querySelector('[data-main-media-badge]');
    if (badge) {
        badge.textContent = isMain ? 'Image principale' : 'Galerie générale';
    }
};

const selectGalleryValue = (select) => {
    if (Array.from(select.options).some((option) => option.value === GALLERY_VALUE)) {
        select.value = GALLERY_VALUE;
    }
};

const syncMainMediaSelect = (select) => {
    const selectedCard = select.closest('[data-main-media-card]');
    if (!selectedCard) {
        return;
    }

    if (select.value !== MAIN_VALUE) {
        setCardState(selectedCard, false);
        return;
    }

    document.querySelectorAll('[data-main-media-card]').forEach((card) => {
        const cardSelect = card.querySelector('[data-main-media-select]');
        const isSelectedCard = card === selectedCard;

        if (!isSelectedCard && cardSelect?.value === MAIN_VALUE) {
            selectGalleryValue(cardSelect);
        }

        setCardState(card, isSelectedCard);
    });
};

const initMainMediaCards = () => {
    document.querySelectorAll('[data-main-media-select]').forEach((select) => {
        syncMainMediaSelect(select);
    });
};

document.addEventListener('change', (event) => {
    const select = event.target instanceof HTMLSelectElement
        ? event.target.closest('[data-main-media-select]')
        : null;

    if (select instanceof HTMLSelectElement) {
        syncMainMediaSelect(select);
    }
});

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMainMediaCards, { once: true });
} else {
    initMainMediaCards();
}
