const quickNavs = Array.from(document.querySelectorAll('[data-studio-quick-nav]'));

const setExpanded = (nav, expanded) => {
    const toggle = nav.querySelector('[data-studio-quick-nav-toggle]');
    nav.classList.toggle('is-collapsed', !expanded);

    if (toggle) {
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }
};

quickNavs.forEach((nav) => {
    setExpanded(nav, false);

    nav.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const toggle = event.target.closest('[data-studio-quick-nav-toggle]');
        if (toggle) {
            setExpanded(nav, nav.classList.contains('is-collapsed'));
            return;
        }

        const link = event.target.closest('.studio-quick-nav__links a');
        if (link) {
            setExpanded(nav, false);
        }
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }

    quickNavs.forEach((nav) => setExpanded(nav, false));
});
