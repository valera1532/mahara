(() => {
  'use strict';

  const instances = new WeakMap();
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const padded = value => String(value).padStart(2, '0');
  let galleryId = 0;

  function source(value) {
    if (typeof value !== 'string') return null;
    try {
      const url = new URL(value, location.href);
      return url.origin === location.origin && !url.search && !url.hash && /^\/assets\/(?:images|uploads)\/[a-zA-Z0-9][a-zA-Z0-9._-]*\.(?:webp|png|jpe?g)$/i.test(url.pathname) ? url.pathname : null;
    } catch { return null; }
  }

  function sync(state, index) {
    state.current = Math.max(0, Math.min(state.slides.length - 1, index));
    state.root.dataset.galleryIndex = String(state.current);
    state.slides.forEach((slide, position) => {
      const active = position === state.current;
      slide.classList.toggle('is-gallery-active', active);
      slide.setAttribute('aria-hidden', String(!active));
      slide.setAttribute('role', 'group');
      slide.setAttribute('aria-roledescription', 'слайд');
      slide.setAttribute('aria-label', `Фото ${position + 1} из ${state.slides.length}`);
      const image = slide.querySelector('img');
      if (image) { image.draggable = false; if (active) image.loading = 'eager'; }
    });
    state.thumbs.forEach(thumb => {
      const index = Number(thumb.dataset.galleryThumb);
      thumb.type = 'button';
      thumb.setAttribute('aria-pressed', String(index === state.current));
      thumb.setAttribute('aria-controls', state.viewport.id);
      if (!thumb.hasAttribute('aria-label')) thumb.setAttribute('aria-label', `Показать фото ${index + 1}`);
    });
    for (const [button, disabled, label] of [[state.prev, state.current === 0, 'Предыдущее фото'], [state.next, state.current === state.slides.length - 1, 'Следующее фото']]) {
      if (!button) continue;
      button.type = 'button'; button.disabled = disabled;
      button.setAttribute('aria-disabled', String(disabled));
      button.setAttribute('aria-label', label);
      button.setAttribute('aria-controls', state.viewport.id);
    }
    const current = state.root.querySelector('[data-gallery-current]');
    const total = state.root.querySelector('[data-gallery-total]');
    if (current) current.textContent = padded(state.current + 1);
    if (total) total.textContent = padded(state.slides.length);
  }

  function stopSwiper(state) {
    const swiper = state.swiper || state.viewport.swiper;
    state.swiper = null;
    try { if (swiper && !swiper.destroyed) swiper.destroy(true, true); } catch { /* The still image remains usable. */ }
    state.viewport.removeAttribute('style');
    for (const name of [...state.viewport.classList]) if (name.startsWith('swiper-')) state.viewport.classList.remove(name);
    state.wrapper.removeAttribute('style');
    state.slides.forEach(slide => slide.removeAttribute('style'));
    state.viewport.querySelectorAll(':scope > .swiper-notification').forEach(node => node.remove());
  }

  function mount(state) {
    const index = state.current;
    stopSwiper(state);
    state.root.dataset.galleryMode = state.slides.length === 1 ? 'single' : 'fallback';
    state.root.dataset.galleryMotion = motion.matches ? 'reduced' : 'full';
    sync(state, index);
    if (state.slides.length > 1 && typeof window.Swiper === 'function') {
      try {
        state.root.dataset.galleryMode = 'swiper';
        state.swiper = new window.Swiper(state.viewport, {
          initialSlide: index,
          slidesPerView: 1,
          slidesPerGroup: 1,
          spaceBetween: 0,
          loop: false,
          rewind: false,
          speed: motion.matches ? 0 : 480,
          effect: motion.matches ? 'slide' : 'creative',
          creativeEffect: {
            perspective: false,
            limitProgress: 1,
            prev: { translate: ['-10%', 0, 0], opacity: 0, scale: .97 },
            next: { translate: ['10%', 0, 0], opacity: 0, scale: .97 },
          },
          grabCursor: true,
          simulateTouch: true,
          threshold: 8,
          touchReleaseOnEdges: true,
          watchOverflow: true,
          lazyPreloadPrevNext: 1,
          keyboard: { enabled: false },
          autoplay: false,
          a11y: { enabled: false },
          on: {
            init(swiper) { sync(state, swiper.activeIndex); },
            slideChange(swiper) { sync(state, swiper.activeIndex); },
          },
        });
        sync(state, state.swiper.activeIndex);
      } catch {
        stopSwiper(state);
        state.root.dataset.galleryMode = 'fallback';
        sync(state, index);
      }
    }
    state.root.dataset.galleryReady = 'true';
  }

  function init(root) {
    if (!(root instanceof Element) || !root.matches('[data-product-gallery]')) return null;
    const viewport = root.querySelector('.product-gallery__viewport');
    const wrapper = viewport?.querySelector('.swiper-wrapper');
    const slides = wrapper ? [...wrapper.children].filter(node => node.classList.contains('swiper-slide')) : [];
    if (!viewport || !slides.length) return null;
    const previous = instances.get(root);
    if (previous?.viewport === viewport) return previous;
    previous?.dispose();
    if (!viewport.id) viewport.id = `mahara-product-gallery-${++galleryId}`;
    const state = { root, viewport, wrapper, slides, thumbs: [...root.querySelectorAll('[data-gallery-thumb]')], prev: root.querySelector('[data-gallery-prev]'), next: root.querySelector('[data-gallery-next]'), current: 0, swiper: null };
    const cleanup = [];
    const listen = (node, event, callback) => { if (node) { node.addEventListener(event, callback); cleanup.push(() => node.removeEventListener(event, callback)); } };
    const go = index => {
      const target = Math.max(0, Math.min(slides.length - 1, index));
      const image = slides[target]?.querySelector('img'); if (image) image.loading = 'eager';
      if (state.swiper && !state.swiper.destroyed) state.swiper.slideTo(target);
      else sync(state, target);
    };
    listen(state.prev, 'click', () => go(state.current - 1));
    listen(state.next, 'click', () => go(state.current + 1));
    state.thumbs.forEach(thumb => listen(thumb, 'click', () => {
      const index = Number(thumb.dataset.galleryThumb);
      if (Number.isInteger(index) && index >= 0 && index < slides.length) go(index);
    }));
    if (slides.length > 1) root.tabIndex = 0;
    else root.removeAttribute('tabindex');
    listen(root, 'keydown', event => {
      if (slides.length < 2 || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || !(event.target instanceof Element) || event.target.closest('input, textarea, select, [contenteditable], [role="textbox"]')) return;
      const index = event.key === 'ArrowLeft' ? state.current - 1 : event.key === 'ArrowRight' ? state.current + 1 : event.key === 'Home' ? 0 : event.key === 'End' ? slides.length - 1 : null;
      if (index === null) return;
      event.preventDefault(); go(index);
    });
    const counter = root.querySelector('.product-gallery__counter');
    if (counter) { counter.setAttribute('role', 'status'); counter.setAttribute('aria-live', 'polite'); counter.setAttribute('aria-atomic', 'true'); }
    const motionChange = () => mount(state);
    if (slides.length > 1) {
      if (motion.addEventListener) { motion.addEventListener('change', motionChange); cleanup.push(() => motion.removeEventListener('change', motionChange)); }
      else { motion.addListener(motionChange); cleanup.push(() => motion.removeListener(motionChange)); }
    }
    state.dispose = () => { cleanup.forEach(remove => remove()); stopSwiper(state); instances.delete(root); };
    instances.set(root, state);
    mount(state);
    return state;
  }

  function setProduct(root, product) {
    if (!(root instanceof Element) || !root.matches('[data-product-gallery]') || !product || typeof product !== 'object') return;
    const photos = [...new Set([product.image, product.altImage].map(source).filter(Boolean))];
    if (!photos.length) return;
    const title = typeof product.title === 'string' && product.title.trim() ? product.title.trim() : 'Товар MAHARA';
    const width = Number.isSafeInteger(product.width) && product.width > 0 ? product.width : 1200;
    const height = Number.isSafeInteger(product.height) && product.height > 0 ? product.height : 1800;
    const oldPhotos = [...root.querySelectorAll('.product-gallery__viewport > .swiper-wrapper > .swiper-slide > img')].map(image => source(image.getAttribute('src')));
    root.setAttribute('aria-label', `Фотографии: ${title}`);
    root.setAttribute('role', 'region');
    if (JSON.stringify(oldPhotos) === JSON.stringify(photos)) {
      root.querySelectorAll('.swiper-slide > img').forEach((image, index) => {
        image.alt = `${title}${index ? ' — другой ракурс' : ''}`;
        image.width = width; image.height = height;
      });
      init(root);
      return;
    }
    instances.get(root)?.dispose();
    delete root.dataset.galleryReady;
    delete root.dataset.galleryMode;
    const viewport = document.createElement('div'); viewport.className = 'product-gallery__viewport swiper';
    const wrapper = document.createElement('div'); wrapper.className = 'swiper-wrapper';
    photos.forEach((url, index) => {
      const slide = document.createElement('div'); slide.className = `swiper-slide${index ? '' : ' is-gallery-active'}`;
      const image = document.createElement('img'); image.src = url; image.alt = `${title}${index ? ' — другой ракурс' : ''}`;
      image.width = width; image.height = height; image.loading = index ? 'lazy' : 'eager'; image.decoding = 'async';
      image.setAttribute('fetchpriority', index ? 'auto' : 'high');
      slide.append(image); wrapper.append(slide);
    });
    viewport.append(wrapper);
    const nodes = [viewport];
    if (photos.length > 1) {
      const controls = document.createElement('div'); controls.className = 'product-gallery__controls';
      const arrow = (next) => {
        const button = document.createElement('button'); button.type = 'button'; button.setAttribute(next ? 'data-gallery-next' : 'data-gallery-prev', '');
        button.setAttribute('aria-label', next ? 'Следующее фото' : 'Предыдущее фото');
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('fill', 'none'); svg.setAttribute('aria-hidden', 'true');
        const line = document.createElementNS(svg.namespaceURI, 'path'); line.setAttribute('d', next ? 'm9 5 7 7-7 7' : 'm15 5-7 7 7 7'); line.setAttribute('stroke-width', '1.5');
        svg.append(line); button.append(svg); return button;
      };
      const counter = document.createElement('span'); counter.className = 'product-gallery__counter';
      const current = document.createElement('span'); current.setAttribute('data-gallery-current', ''); current.textContent = '01';
      const divider = document.createElement('span'); divider.textContent = '/'; divider.setAttribute('aria-hidden', 'true');
      const total = document.createElement('span'); total.setAttribute('data-gallery-total', ''); total.textContent = padded(photos.length);
      counter.append(current, divider, total); controls.append(arrow(false), counter, arrow(true));
      const thumbs = document.createElement('div'); thumbs.className = 'product-gallery__thumbs'; thumbs.setAttribute('role', 'group'); thumbs.setAttribute('aria-label', 'Выбрать фотографию');
      photos.forEach((url, index) => {
        const button = document.createElement('button'); button.className = 'product-gallery__thumb'; button.type = 'button'; button.dataset.galleryThumb = String(index);
        button.setAttribute('aria-label', `Показать фото ${index + 1}`); button.setAttribute('aria-pressed', String(index === 0));
        const image = document.createElement('img'); image.src = url; image.alt = ''; image.width = 54; image.height = 76; image.loading = 'lazy'; image.decoding = 'async';
        button.append(image); thumbs.append(button);
      });
      nodes.push(controls, thumbs);
    }
    root.replaceChildren(...nodes);
    init(root);
  }

  window.MaharaProductGallery = Object.freeze({ init, setProduct });
  const start = () => document.querySelectorAll('[data-product-gallery]').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
