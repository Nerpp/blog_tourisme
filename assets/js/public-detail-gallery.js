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

      const loadSlideImage = (index) => {
        const slide = slides[(index + slides.length) % slides.length];
        const image = slide ? slide.querySelector('img[data-gallery-src]') : null;

        if (!image || image.src) {
          return;
        }

        image.src = image.dataset.gallerySrc;
      };

      const appendAutoplayParam = (url) => {
        if (!url) {
          return url;
        }

        try {
          const parsedUrl = new URL(url, window.location.href);
          parsedUrl.searchParams.set('autoplay', '1');

          return parsedUrl.toString();
        } catch (error) {
          const [baseUrl, hash = ''] = url.split('#');
          const separator = baseUrl.includes('?') ? '&' : '?';

          return `${baseUrl}${separator}autoplay=1${hash ? `#${hash}` : ''}`;
        }
      };

      const loadSlideVideos = (index) => {
        const slide = slides[(index + slides.length) % slides.length];
        const iframes = slide ? slide.querySelectorAll('iframe[data-video-src]') : [];
        const videos = slide ? slide.querySelectorAll('video[data-video-src]') : [];

        iframes.forEach((iframe) => {
          if (!iframe.src && iframe.dataset.videoSrc) {
            iframe.src = appendAutoplayParam(iframe.dataset.videoSrc);
          }
        });

        videos.forEach((video) => {
          if (!video.dataset.videoSrc) {
            return;
          }

          if (video.dataset.videoLoaded !== 'true') {
            const source = document.createElement('source');
            source.src = video.dataset.videoSrc;
            source.type = video.dataset.videoType || 'video/mp4';
            video.insertBefore(source, video.firstChild);
            video.dataset.videoLoaded = 'true';
            video.load();
          }

          const playPromise = video.play();
          if (playPromise && typeof playPromise.catch === 'function') {
            playPromise.catch(() => {
              // Browser autoplay policy can block playback with sound; controls remain visible.
            });
          }
        });
      };

      const stopSlideVideos = (slide) => {
        if (!slide) {
          return;
        }

        slide.querySelectorAll('video').forEach((video) => {
          video.pause();
          try {
            video.currentTime = 0;
          } catch (error) {
            // Some browsers reject currentTime updates until metadata is loaded.
          }
          video.querySelectorAll('source').forEach((source) => source.remove());
          video.removeAttribute('src');
          video.dataset.videoLoaded = 'false';
          video.load();
        });

        slide.querySelectorAll('iframe[data-video-src]').forEach((iframe) => {
          iframe.removeAttribute('src');
        });
      };

      const stopInactiveVideos = () => {
        slides.forEach((slide, slideIndex) => {
          if (slideIndex !== currentIndex) {
            stopSlideVideos(slide);
          }
        });
      };

      const stopAllVideos = () => {
        slides.forEach((slide) => stopSlideVideos(slide));
      };

      const preloadSlideImage = (index) => {
        const slide = slides[(index + slides.length) % slides.length];
        const image = slide ? slide.querySelector('img[data-gallery-src]') : null;
        const src = image ? image.dataset.gallerySrc : null;

        if (!src) {
          return;
        }

        const preloadImage = new Image();
        preloadImage.src = src;
      };

      const preloadNeighborSlides = () => {
        if (slides.length < 2) {
          return;
        }

        preloadSlideImage(currentIndex + 1);
        preloadSlideImage(currentIndex - 1);
      };

      const activateSlideViewers = () => {
        const slide = slides[currentIndex];
        const viewers = slide ? slide.querySelectorAll('.js-panorama-viewer') : [];

        viewers.forEach((viewer) => {
          requestAnimationFrame(() => {
            viewer.dispatchEvent(new CustomEvent('public-detail:panorama-activate', { bubbles: true }));
          });
        });
      };

      const setActiveSlide = (index, shouldLoadImages = true) => {
        const nextIndex = (index + slides.length) % slides.length;

        if (shouldLoadImages && nextIndex !== currentIndex) {
          stopSlideVideos(slides[currentIndex]);
        }

        currentIndex = nextIndex;

        if (shouldLoadImages) {
          loadSlideImage(currentIndex);
          loadSlideVideos(currentIndex);
        }

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

        if (shouldLoadImages) {
          stopInactiveVideos();
          activateSlideViewers();
          preloadNeighborSlides();
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
        stopAllVideos();

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
        button.addEventListener('click', (event) => {
          event.preventDefault();

          const index = Number.parseInt(button.dataset.galleryIndex || '0', 10);

          openModal(Number.isNaN(index) ? 0 : index);
        });
      });

      setActiveSlide(0, false);
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
}
