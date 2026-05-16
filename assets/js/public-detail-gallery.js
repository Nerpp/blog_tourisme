export function initPublicDetailGallery() {
  const init = () => {
    const modals = document.querySelectorAll('.js-gallery-modal');

    modals.forEach((modal) => {
      const slides = Array.from(modal.querySelectorAll('.js-gallery-slide'));
      const dots = Array.from(modal.querySelectorAll('.js-gallery-dot'));
      const closeButtons = modal.querySelectorAll('.js-gallery-close');
      const nextButtons = modal.querySelectorAll('.js-gallery-next');
      const prevButtons = modal.querySelectorAll('.js-gallery-prev');
      const counter = modal.querySelector('.js-gallery-counter');

      if (slides.length === 0) {
        return;
      }

      let currentIndex = 0;
      let previousFocus = null;

      const setActiveSlide = (index) => {
        currentIndex = (index + slides.length) % slides.length;

        slides.forEach((slide, slideIndex) => {
          slide.classList.toggle('is-active', slideIndex === currentIndex);
        });

        dots.forEach((dot, dotIndex) => {
          const isActive = dotIndex === currentIndex;

          dot.classList.toggle('is-active', isActive);
          dot.setAttribute('aria-current', isActive ? 'true' : 'false');
        });

        if (counter) {
          counter.textContent = `${currentIndex + 1} / ${slides.length}`;
        }
      };

      const openModal = (index = 0) => {
        previousFocus = document.activeElement;

        modal.hidden = false;
        modal.removeAttribute('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('has-gallery-modal');

        setActiveSlide(index);

        const closeButton = modal.querySelector('.js-gallery-close');

        if (closeButton) {
          closeButton.focus();
        }
      };

      const closeModal = () => {
        modal.hidden = true;
        modal.setAttribute('hidden', '');
        modal.setAttribute('aria-hidden', 'true');
        document.documentElement.classList.remove('has-gallery-modal');

        if (previousFocus && typeof previousFocus.focus === 'function') {
          previousFocus.focus();
        }
      };

      nextButtons.forEach((button) => {
        button.addEventListener('click', () => {
          setActiveSlide(currentIndex + 1);
        });
      });

      prevButtons.forEach((button) => {
        button.addEventListener('click', () => {
          setActiveSlide(currentIndex - 1);
        });
      });

      closeButtons.forEach((button) => {
        button.addEventListener('click', closeModal);
      });

      dots.forEach((dot, index) => {
        dot.addEventListener('click', () => {
          setActiveSlide(index);
        });
      });

      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          closeModal();
        }
      });

      modal.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closeModal();
        }

        if (event.key === 'ArrowRight') {
          setActiveSlide(currentIndex + 1);
        }

        if (event.key === 'ArrowLeft') {
          setActiveSlide(currentIndex - 1);
        }
      });

      const openButtons = document.querySelectorAll(`[data-gallery-target="#${modal.id}"]`);

      openButtons.forEach((button) => {
        button.addEventListener('click', () => {
          const index = Number.parseInt(button.dataset.galleryIndex || '0', 10);

          openModal(Number.isNaN(index) ? 0 : index);
        });
      });

      setActiveSlide(0);
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
}