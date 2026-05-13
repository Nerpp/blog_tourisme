export function initNavbar() {
  const toggler = document.querySelector('.js-navbar-toggler');
  const collapse = document.querySelector('.js-navbar-collapse');

  if (!toggler || !collapse) {
    return;
  }

  const closeNavbar = () => {
    collapse.classList.remove('is-open');
    toggler.setAttribute('aria-expanded', 'false');
  };

  const toggleNavbar = () => {
    const isOpen = collapse.classList.toggle('is-open');
    toggler.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  };

  toggler.addEventListener('click', toggleNavbar);

  collapse.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', closeNavbar);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeNavbar();
    }
  });

  document.addEventListener('click', (event) => {
    const clickInsideNavbar = toggler.contains(event.target) || collapse.contains(event.target);

    if (!clickInsideNavbar) {
      closeNavbar();
    }
  });
}