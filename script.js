(() => {
  'use strict';

  const { products: PRODUCTS, card: productCard } = window.MaharaCatalog;
  document.documentElement.classList.add('js');

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const formatPrice = value => `${new Intl.NumberFormat('ru-RU').format(value)} ₽`;
  const pluralProducts = value => {
    const mod10 = value % 10;
    const mod100 = value % 100;
    if (mod10 === 1 && mod100 !== 11) return `${value} товар`;
    if ([2,3,4].includes(mod10) && ![12,13,14].includes(mod100)) return `${value} товара`;
    return `${value} товаров`;
  };

  const state = {
    cart: readCart(),
    quickProduct: null,
    quickSize: null,
    filters: {
      category: 'all',
      sizes: new Set(),
      colors: new Set(),
      sort: 'new',
      query: ''
    }
  };

  function readCart() {
    try {
      const parsed = JSON.parse(localStorage.getItem('maharaCart') || '[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch {
      return [];
    }
  }

  function saveCart() {
    try { localStorage.setItem('maharaCart', JSON.stringify(state.cart)); } catch { /* preview/file mode */ }
  }

  const initialProductGrids = new WeakMap();

  function renderProducts() {
    $$('[data-product-grid]').forEach(grid => {
      const type = grid.dataset.productGrid;
      const products = type === 'featured' ? PRODUCTS.slice(0, 4) : filteredProducts();
      const currentIds = $$('.product-card', grid).map(card => card.dataset.productId).join('|');
      if (!initialProductGrids.has(grid)) initialProductGrids.set(grid, { html: grid.innerHTML, ids: currentIds });
      const desiredIds = products.map(product => product.id).join('|');
      if (currentIds !== desiredIds) {
        const initial = initialProductGrids.get(grid);
        grid.innerHTML = desiredIds === initial.ids ? initial.html : products.map(product => productCard(product)).join('');
      }
      if (type === 'catalog') {
        const count = $('[data-results-count]');
        const empty = $('[data-catalog-empty]');
        if (count) {
          count.setAttribute('role', 'status');
          count.setAttribute('aria-live', 'polite');
          count.setAttribute('aria-atomic', 'true');
          count.textContent = pluralProducts(products.length);
        }
        if (empty) empty.classList.toggle('is-visible', products.length === 0);
      }
      bindProductCards(grid);
      observeReveals(grid);
    });
  }

  function filteredProducts() {
    let result = [...PRODUCTS];
    const { category, sizes, colors, sort, query } = state.filters;

    if (category !== 'all') result = result.filter(product => product.category === category);
    if (sizes.size) result = result.filter(product => [...sizes].some(size => product.sizes.includes(size)));
    if (colors.size) result = result.filter(product => colors.has(product.color));
    if (query) {
      const normalized = query.toLocaleLowerCase('ru-RU');
      result = result.filter(product => `${product.title} ${product.subtitle} ${product.description}`.toLocaleLowerCase('ru-RU').includes(normalized));
    }

    if (sort === 'price-asc') result.sort((a, b) => a.price - b.price);
    if (sort === 'price-desc') result.sort((a, b) => b.price - a.price);
    return result;
  }

  function bindProductCards(root) {
    $$('.product-card, [data-product-detail]', root).forEach(card => {
      const product = PRODUCTS.find(item => item.id === card.dataset.productId);
      if (!product || card.dataset.productBound) return;
      card.dataset.productBound = 'true';

      $('[data-favorite]', card)?.addEventListener('click', event => {
        const button = event.currentTarget;
        const active = button.classList.toggle('is-active');
        button.setAttribute('aria-pressed', String(active));
        button.setAttribute('aria-label', `${active ? 'Удалить из избранного' : 'Добавить в избранное'}: ${product.title}`);
        showToast(active ? 'Добавлено в избранное' : 'Удалено из избранного');
      });

      $$('[data-size-choice]', card).forEach(button => {
        button.addEventListener('click', () => {
          $$('[data-size-choice]', card).forEach(item => {
            item.classList.remove('is-active');
            item.setAttribute('aria-pressed', 'false');
          });
          button.classList.add('is-active');
          button.setAttribute('aria-pressed', 'true');
        });
      });

      $('[data-add-cart]', card)?.addEventListener('click', () => {
        const size = $('[data-size-choice].is-active', card)?.dataset.sizeChoice || product.sizes[0];
        addToCart(product.id, size);
      });

      $('[data-quick-open]', card)?.addEventListener('click', () => openQuickView(product));
      const quickButton = $('[data-quick-open]', card);
      if (quickButton) {
        quickButton.setAttribute('aria-haspopup', 'dialog');
        quickButton.setAttribute('aria-expanded', 'false');
        const dialogId = $('[data-quick-view]')?.id;
        if (dialogId) quickButton.setAttribute('aria-controls', dialogId);
      }
    });
  }

  function addToCart(productId, size) {
    const existing = state.cart.find(item => item.id === productId && item.size === size);
    if (existing) existing.qty += 1;
    else state.cart.push({ id: productId, size, qty: 1 });
    saveCart();
    updateCartUI();
    const product = PRODUCTS.find(item => item.id === productId);
    showToast(`${product?.title || 'Товар'} · ${size} — в корзине`);
  }

  function removeCartItem(index) {
    state.cart.splice(index, 1);
    saveCart();
    updateCartUI();
  }

  function updateCartUI() {
    const count = state.cart.reduce((sum, item) => sum + item.qty, 0);
    $$('[data-cart-count]').forEach(node => node.textContent = count);
    $$('.cart-trigger').forEach(button => button.setAttribute('aria-label', `Корзина, ${pluralProducts(count)}`));
    const caption = $('[data-cart-caption]');
    const totalNode = $('[data-cart-total]');
    const itemsNode = $('[data-cart-items]');
    const emptyNode = $('[data-cart-empty]');
    const footer = $('[data-cart-footer]');

    if (caption) {
      caption.setAttribute('role', 'status');
      caption.setAttribute('aria-live', 'polite');
      caption.setAttribute('aria-atomic', 'true');
      caption.textContent = pluralProducts(count);
    }
    if (!itemsNode) return;

    const total = state.cart.reduce((sum, item) => {
      const product = PRODUCTS.find(p => p.id === item.id);
      return sum + (product?.price || 0) * item.qty;
    }, 0);
    if (totalNode) totalNode.textContent = formatPrice(total);

    const focusedRemove = document.activeElement?.closest('[data-cart-remove]');
    const removedIndex = focusedRemove && itemsNode.contains(focusedRemove) ? Number(focusedRemove.dataset.cartRemove) : null;
    itemsNode.innerHTML = state.cart.map((item, index) => {
      const product = PRODUCTS.find(p => p.id === item.id);
      if (!product) return '';
      return `
        <div class="cart-item">
          <img src="${product.image}" alt="${product.title}" width="${product.width}" height="${product.height}" decoding="async" loading="lazy" />
          <div class="cart-item__copy">
            <strong>${product.title}</strong>
            <span>Размер ${item.size} · ${item.qty} шт</span>
            <b>${formatPrice(product.price * item.qty)}</b>
          </div>
          <button class="cart-item__remove" type="button" data-cart-remove="${index}" aria-label="Удалить из корзины: ${product.title}, размер ${item.size}">×</button>
        </div>`;
    }).join('');

    $$('[data-cart-remove]', itemsNode).forEach(button => {
      button.addEventListener('click', () => removeCartItem(Number(button.dataset.cartRemove)));
    });

    const hasItems = count > 0;
    if (emptyNode) emptyNode.style.display = hasItems ? 'none' : 'grid';
    if (footer) footer.style.display = hasItems ? 'block' : 'none';
    if (removedIndex !== null) {
      const buttons = $$('[data-cart-remove]', itemsNode);
      (buttons[Math.min(removedIndex, buttons.length - 1)] || $('.cart-close'))?.focus({ preventScroll: true });
    }
  }

  const filterViewport = matchMedia('(max-width: 700px)');
  const menuViewport = matchMedia('(max-width: 920px)');
  const overlayPanels = [
    { element: $('[data-mobile-menu]'), bodyClass: 'menu-open', triggers: '[data-menu-toggle]' },
    { element: $('[data-search-panel]'), bodyClass: 'search-open', triggers: '.search-trigger' },
    { element: $('[data-cart-drawer]'), bodyClass: 'drawer-open', triggers: '.cart-trigger' },
    { element: $('[data-quick-view]'), bodyClass: 'modal-open', triggers: '[data-quick-open]' },
    { element: $('[data-filter-panel]'), bodyClass: 'filter-open', triggers: '[data-filter-open]' }
  ].filter(panel => panel.element);
  const changedInert = new Map();
  let activeOverlay = null;
  let overlayOpener = null;

  const visibleControl = element => element && element.getClientRects().length > 0 &&
    getComputedStyle(element).visibility !== 'hidden' && !element.closest('[inert]');

  const focusableControls = panel => $$('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', panel)
    .filter(element => visibleControl(element) && element.tabIndex >= 0);

  function restoreBackground() {
    changedInert.forEach((wasInert, element) => { element.inert = wasInert; });
    changedInert.clear();
  }

  function setBackgroundInert(element, inert) {
    if (!changedInert.has(element)) changedInert.set(element, element.inert);
    element.inert = inert;
  }

  function syncOverlayState() {
    restoreBackground();
    overlayPanels.forEach(panel => {
      const isFilter = panel.element.matches('[data-filter-panel]');
      const desktopFilter = isFilter && !filterViewport.matches;
      const open = panel.element === activeOverlay;
      document.body.classList.toggle(panel.bodyClass, open);
      panel.element.inert = !desktopFilter && !open;
      panel.element.setAttribute('aria-hidden', String(!desktopFilter && !open));
      if (isFilter) {
        panel.element.setAttribute('role', desktopFilter ? 'region' : 'dialog');
        if (!panel.element.hasAttribute('aria-label') && !panel.element.hasAttribute('aria-labelledby')) panel.element.setAttribute('aria-label', 'Фильтры');
        if (desktopFilter) panel.element.removeAttribute('aria-modal');
        else panel.element.setAttribute('aria-modal', 'true');
      }
      $$(panel.triggers).forEach(trigger => {
        trigger.setAttribute('aria-expanded', String(open));
        if (panel.element.id) trigger.setAttribute('aria-controls', panel.element.id);
      });
    });
    if (!activeOverlay) return;

    const drawingOverlay = $('[data-drawer-overlay]');
    const statusToast = $('[data-toast]');
    for (let current = activeOverlay; current && current !== document.body; current = current.parentElement) {
      setBackgroundInert(current, false);
      Array.from(current.parentElement?.children || []).forEach(sibling => {
        if (sibling !== current && sibling !== drawingOverlay && sibling !== statusToast) setBackgroundInert(sibling, true);
      });
    }
  }

  function focusOverlay(panel) {
    const searchInput = panel.querySelector('input[type="search"]');
    if (searchInput && !visibleControl(searchInput)) return false;
    const target = searchInput || focusableControls(panel)[0];
    if (target) {
      target.focus({ preventScroll: true });
    } else if (!panel.querySelector('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')) {
      panel.setAttribute('tabindex', '-1');
      panel.focus({ preventScroll: true });
    }
    return panel.contains(document.activeElement);
  }

  function openOverlay(panel) {
    if (!panel || (panel.matches('[data-filter-panel]') && !filterViewport.matches)) return;
    if (!activeOverlay) overlayOpener = document.activeElement;
    activeOverlay = panel;
    syncOverlayState();
    const focusDeadline = performance.now() + 1000;
    const focusWhenReady = () => {
      if (activeOverlay !== panel || panel.contains(document.activeElement)) return;
      if (!focusOverlay(panel) && performance.now() < focusDeadline) requestAnimationFrame(focusWhenReady);
    };
    focusWhenReady();
  }

  function closeOverlay(panel = activeOverlay, restoreFocus = true) {
    if (!panel || panel !== activeOverlay) return;
    const opener = overlayOpener;
    activeOverlay = null;
    overlayOpener = null;
    syncOverlayState();
    if (restoreFocus) {
      const target = visibleControl(opener) && opener !== document.body && !opener.matches(':disabled') ? opener : $('.brand');
      if (visibleControl(target)) target.focus({ preventScroll: true });
    }
  }

  function setupOverlayAccessibility() {
    syncOverlayState();
    menuViewport.addEventListener('change', () => {
      if (!menuViewport.matches) closeOverlay($('[data-mobile-menu]'));
    });
    filterViewport.addEventListener('change', () => {
      const filter = $('[data-filter-panel]');
      const wasOpen = filter && activeOverlay === filter;
      const hadFocus = filter?.contains(document.activeElement);
      if (!filterViewport.matches && wasOpen) closeOverlay(filter, false);
      syncOverlayState();
      if (!activeOverlay && (wasOpen || hadFocus)) {
        const target = filterViewport.matches ? $('[data-filter-open]') : filter && focusableControls(filter)[0];
        target?.focus({ preventScroll: true });
      }
    });
    document.addEventListener('keydown', event => {
      if (!activeOverlay) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeOverlay();
      } else if (event.key === 'Tab') {
        const controls = focusableControls(activeOverlay);
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (!first) {
          event.preventDefault();
          focusOverlay(activeOverlay);
        } else if (!activeOverlay.contains(document.activeElement) ||
          (event.shiftKey && document.activeElement === first) ||
          (!event.shiftKey && document.activeElement === last)) {
          event.preventDefault();
          (event.shiftKey ? last : first).focus();
        }
      }
    });
  }

  function openCart() {
    openOverlay($('[data-cart-drawer]'));
  }

  function closeCart() {
    closeOverlay($('[data-cart-drawer]'));
  }

  function openQuickView(product) {
    state.quickProduct = product;
    state.quickSize = product.sizes[0];
    const modal = $('[data-quick-view]');
    if (!modal) return;

    const quickImage = $('[data-quick-image]', modal);
    quickImage.src = product.image;
    quickImage.alt = product.title;
    quickImage.width = product.width;
    quickImage.height = product.height;
    quickImage.decoding = 'async';
    $('[data-quick-title]', modal).textContent = product.title;
    $('[data-quick-description]', modal).textContent = product.description;
    $('[data-quick-price]', modal).textContent = formatPrice(product.price);
    $('[data-quick-label]', modal).textContent = product.label;

    const sizesNode = $('[data-quick-sizes]', modal);
    sizesNode.innerHTML = product.sizes.map((size, index) =>
      `<button type="button" class="${index === 0 ? 'is-active' : ''}" data-quick-size="${size}" aria-pressed="${index === 0}" aria-label="Размер ${size}">${size}</button>`
    ).join('');
    $$('[data-quick-size]', sizesNode).forEach(button => {
      button.addEventListener('click', () => {
        $$('[data-quick-size]', sizesNode).forEach(item => {
          item.classList.remove('is-active');
          item.setAttribute('aria-pressed', 'false');
        });
        button.classList.add('is-active');
        button.setAttribute('aria-pressed', 'true');
        state.quickSize = button.dataset.quickSize;
      });
    });

    openOverlay(modal);
  }

  function closeQuickView() {
    closeOverlay($('[data-quick-view]'));
  }

  let toastTimer;
  function showToast(message) {
    const toast = $('[data-toast]');
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 2400);
  }

  function setupHeader() {
    const header = $('[data-header]');
    if (!header) return;
    const update = () => header.classList.toggle('is-scrolled', window.scrollY > 30);
    update();
    window.addEventListener('scroll', update, { passive: true });
  }

  function setupMenu() {
    const toggle = $('[data-menu-toggle]');
    const menu = $('[data-mobile-menu]');
    if (!toggle || !menu) return;

    toggle.addEventListener('click', () => {
      if (activeOverlay === menu) closeOverlay(menu);
      else openOverlay(menu);
    });
    $('[data-menu-close]', menu)?.addEventListener('click', () => closeOverlay(menu));

    $$('a', menu).forEach(link => link.addEventListener('click', () => {
      closeOverlay(menu);
      if (link.hash && link.pathname === location.pathname) {
        const destination = document.getElementById(link.hash.slice(1));
        if (destination) {
          destination.setAttribute('tabindex', '-1');
          destination.focus({ preventScroll: true });
        }
      }
    }));
  }

  function setupSearch() {
    const panel = $('[data-search-panel]');
    if (!panel) return;
    $$('.search-trigger').forEach(button => button.addEventListener('click', () => {
      openOverlay(panel);
    }));
    $('[data-search-close]')?.addEventListener('click', closeSearch);
  }

  function closeSearch() {
    closeOverlay($('[data-search-panel]'));
  }

  function setupOverlays() {
    $$('.cart-trigger').forEach(button => button.addEventListener('click', openCart));
    $('.cart-close')?.addEventListener('click', closeCart);
    $('[data-drawer-overlay]')?.addEventListener('click', () => {
      closeOverlay();
    });
    $('.quick-view__close')?.addEventListener('click', closeQuickView);
    $('.quick-add')?.addEventListener('click', () => {
      if (!state.quickProduct) return;
      addToCart(state.quickProduct.id, state.quickSize || state.quickProduct.sizes[0]);
      openCart();
    });
  }

  function observeReveals(root = document) {
    const nodes = $$('.reveal:not([data-observed])', root);
    if (!nodes.length) return;
    if (!('IntersectionObserver' in window)) {
      nodes.forEach(node => node.classList.add('is-visible'));
      return;
    }
    const observer = observeReveals.observer || (observeReveals.observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -7% 0px', threshold: .08 }));

    nodes.forEach((node, index) => {
      node.dataset.observed = 'true';
      node.style.transitionDelay = `${Math.min(index % 4, 3) * 70}ms`;
      observer.observe(node);
    });

    const revealVisible = () => {
      $$('.reveal:not(.is-visible)').forEach(node => {
        const rect = node.getBoundingClientRect();
        if (rect.top < innerHeight * .94 && rect.bottom > 0) node.classList.add('is-visible');
      });
    };
    if (!observeReveals.fallbackBound) {
      observeReveals.fallbackBound = true;
      window.addEventListener('scroll', revealVisible, { passive: true });
      window.addEventListener('resize', revealVisible, { passive: true });
      requestAnimationFrame(revealVisible);
    }
  }

  function setupHeroParallax() {
    const hero = $('[data-hero]');
    if (!hero || matchMedia('(pointer: coarse)').matches || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const layers = $$('[data-parallax]', hero);
    let targetX = 0;
    let targetY = 0;
    let currentX = 0;
    let currentY = 0;

    hero.addEventListener('pointermove', event => {
      const rect = hero.getBoundingClientRect();
      targetX = (event.clientX - rect.left) / rect.width - .5;
      targetY = (event.clientY - rect.top) / rect.height - .5;
    });
    hero.addEventListener('pointerleave', () => { targetX = 0; targetY = 0; });

    const tick = () => {
      currentX += (targetX - currentX) * .07;
      currentY += (targetY - currentY) * .07;
      layers.forEach(layer => {
        const amount = Number(layer.dataset.parallax || 0);
        layer.style.transform = `translate3d(${currentX * amount}px, ${currentY * amount * .55}px, 0)`;
      });
      requestAnimationFrame(tick);
    };
    tick();
  }

  function setupMagnetic() {
    if (matchMedia('(pointer: coarse)').matches || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    $$('.magnetic').forEach(button => {
      button.addEventListener('pointermove', event => {
        const rect = button.getBoundingClientRect();
        const x = event.clientX - rect.left - rect.width / 2;
        const y = event.clientY - rect.top - rect.height / 2;
        button.style.transform = `translate(${x * .08}px, ${y * .12}px)`;
      });
      button.addEventListener('pointerleave', () => button.style.transform = '');
    });
  }

  function setupLookbook() {
    const slider = $('[data-lookbook-slider]');
    if (!slider) return;

    const cards = $$('.lookbook-card', slider);
    const previous = $('[data-lookbook-prev]');
    const next = $('[data-lookbook-next]');
    const pagination = $('[data-lookbook-pagination]');
    if (!cards.length || !previous || !next || !pagination) return;

    const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
    let stops = [0];
    let maxScroll = 0;
    let requestedIndex = null;
    let mouseDrag = null;
    let nativeTouch = false;
    let settling = false;
    let settleFrame = 0;
    let dragFrame = 0;
    let scrollFrame = 0;
    let resizeFrame = 0;
    let scrollTimer;

    const nearestIndex = () => stops.reduce((nearest, stop, index) =>
      Math.abs(stop - slider.scrollLeft) < Math.abs(stops[nearest] - slider.scrollLeft) ? index : nearest, 0);

    const syncControls = () => {
      const active = nearestIndex();
      previous.disabled = slider.scrollLeft <= 1;
      next.disabled = slider.scrollLeft >= maxScroll - 1;
      $$('.lookbook-pagination__dot', pagination).forEach((dot, index) => {
        dot.classList.toggle('is-active', index === active);
        if (index === active) dot.setAttribute('aria-current', 'true');
        else dot.removeAttribute('aria-current');
      });
    };

    const cancelSettle = () => {
      cancelAnimationFrame(settleFrame);
      settleFrame = 0;
      settling = false;
      requestedIndex = null;
      clearTimeout(scrollTimer);
    };

    const goTo = index => {
      cancelSettle();
      requestedIndex = Math.max(0, Math.min(index, stops.length - 1));
      const start = slider.scrollLeft;
      const target = stops[requestedIndex];
      slider.classList.add('is-settling');

      const complete = () => {
        slider.scrollLeft = target;
        settleFrame = 0;
        settling = false;
        requestedIndex = null;
        slider.classList.remove('is-settling');
        syncControls();
      };
      if (reducedMotion.matches || Math.abs(target - start) <= .5) {
        complete();
        return;
      }

      settling = true;
      let startedAt;
      const step = timestamp => {
        startedAt ??= timestamp;
        const progress = Math.min(1, (timestamp - startedAt) / 480);
        const eased = 1 - Math.pow(1 - progress, 3);
        slider.scrollLeft = start + (target - start) * eased;
        if (progress < 1) settleFrame = requestAnimationFrame(step);
        else complete();
      };
      settleFrame = requestAnimationFrame(step);
    };

    const finishScroll = () => {
      if (settling || mouseDrag || nativeTouch) return;
      if (slider.classList.contains('is-settling')) goTo(nearestIndex());
      else syncControls();
    };

    const scheduleFinish = () => {
      clearTimeout(scrollTimer);
      scrollTimer = setTimeout(finishScroll, 160);
    };

    const flushDrag = () => {
      cancelAnimationFrame(dragFrame);
      dragFrame = 0;
      if (mouseDrag?.moved) slider.scrollLeft = mouseDrag.pendingScroll;
    };

    const measure = () => {
      cancelSettle();
      slider.classList.add('is-settling');
      if (mouseDrag) {
        flushDrag();
        const pointerId = mouseDrag.pointerId;
        mouseDrag = null;
        slider.classList.remove('is-dragging');
        if (slider.hasPointerCapture(pointerId)) slider.releasePointerCapture(pointerId);
      }
      const gutter = parseFloat(getComputedStyle(slider).paddingLeft) || 0;
      const origin = slider.getBoundingClientRect().left + slider.clientLeft;
      maxScroll = Math.max(0, slider.scrollWidth - slider.clientWidth);
      const measured = [0];
      cards.forEach(card => {
        const offset = card.getBoundingClientRect().left - origin + slider.scrollLeft - gutter;
        const target = Math.max(0, Math.min(offset, maxScroll));
        if (target - measured[measured.length - 1] > 1) measured.push(target);
      });
      if (maxScroll - measured[measured.length - 1] > 1) measured.push(maxScroll);
      else measured[measured.length - 1] = maxScroll;
      stops = measured;

      if (pagination.children.length !== stops.length) {
        const focusedIndex = Array.from(pagination.children).indexOf(document.activeElement);
        pagination.replaceChildren(...stops.map((stop, index) => {
          const dot = document.createElement('button');
          dot.className = 'lookbook-pagination__dot';
          dot.type = 'button';
          dot.dataset.lookbookPosition = index;
          dot.setAttribute('aria-label', `Позиция ${index + 1} из ${stops.length}`);
          dot.setAttribute('aria-controls', slider.id);
          return dot;
        }));
        if (focusedIndex >= 0) pagination.children[Math.min(focusedIndex, stops.length - 1)].focus({ preventScroll: true });
      }

      if (!nativeTouch) goTo(nearestIndex());
      syncControls();
    };

    const scheduleMeasure = () => {
      cancelSettle();
      cancelAnimationFrame(resizeFrame);
      resizeFrame = requestAnimationFrame(measure);
    };

    previous.addEventListener('click', () => goTo((requestedIndex ?? nearestIndex()) - 1));
    next.addEventListener('click', () => goTo((requestedIndex ?? nearestIndex()) + 1));
    pagination.addEventListener('click', event => {
      const dot = event.target.closest('[data-lookbook-position]');
      if (dot) goTo(Number(dot.dataset.lookbookPosition));
    });

    slider.addEventListener('keydown', event => {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
      event.preventDefault();
      goTo((requestedIndex ?? nearestIndex()) + (event.key === 'ArrowRight' ? 1 : -1));
    });

    slider.addEventListener('scroll', () => {
      if (!scrollFrame) scrollFrame = requestAnimationFrame(() => {
        scrollFrame = 0;
        syncControls();
      });
      scheduleFinish();
    }, { passive: true });
    slider.addEventListener('scrollend', finishScroll);
    slider.addEventListener('wheel', () => {
      cancelSettle();
      scheduleFinish();
    }, { passive: true });

    slider.addEventListener('touchstart', () => {
      nativeTouch = true;
      cancelSettle();
    }, { passive: true });
    const finishTouch = event => {
      nativeTouch = event.touches.length > 0;
      if (!nativeTouch) scheduleFinish();
    };
    slider.addEventListener('touchend', finishTouch, { passive: true });
    slider.addEventListener('touchcancel', finishTouch, { passive: true });

    slider.addEventListener('pointerdown', event => {
      if (event.pointerType === 'mouse' && event.button !== 0) return;
      cancelSettle();
      if (event.pointerType !== 'mouse' || event.button !== 0) return;
      mouseDrag = { pointerId: event.pointerId, startX: event.clientX, startScroll: slider.scrollLeft, pendingScroll: slider.scrollLeft, moved: false };
      slider.setPointerCapture(event.pointerId);
    });

    slider.addEventListener('pointermove', event => {
      if (!mouseDrag || event.pointerId !== mouseDrag.pointerId) return;
      const distance = event.clientX - mouseDrag.startX;
      if (!mouseDrag.moved && Math.abs(distance) <= 5) return;
      mouseDrag.moved = true;
      slider.classList.add('is-dragging');
      event.preventDefault();
      mouseDrag.pendingScroll = Math.max(0, Math.min(mouseDrag.startScroll - distance, maxScroll));
      if (!dragFrame) dragFrame = requestAnimationFrame(() => {
        dragFrame = 0;
        if (mouseDrag) slider.scrollLeft = mouseDrag.pendingScroll;
      });
    });

    const finishDrag = event => {
      if (!mouseDrag || event.pointerId !== mouseDrag.pointerId) return;
      flushDrag();
      const moved = mouseDrag.moved;
      mouseDrag = null;
      const target = nearestIndex();
      const needsSettle = moved || slider.classList.contains('is-settling');
      if (needsSettle) slider.classList.add('is-settling');
      slider.classList.remove('is-dragging');
      if (slider.hasPointerCapture(event.pointerId)) slider.releasePointerCapture(event.pointerId);
      if (needsSettle) goTo(target);
    };
    slider.addEventListener('pointerup', finishDrag);
    slider.addEventListener('pointercancel', finishDrag);
    slider.addEventListener('lostpointercapture', finishDrag);
    slider.addEventListener('dragstart', event => event.preventDefault());

    window.addEventListener('resize', scheduleMeasure, { passive: true });
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(scheduleMeasure).observe(slider);
    reducedMotion.addEventListener('change', () => {
      if (reducedMotion.matches && requestedIndex !== null) goTo(requestedIndex);
    });
    measure();
  }

  function setupNewsletter() {
    $('[data-newsletter-form]')?.addEventListener('submit', event => {
      event.preventDefault();
      showToast('Форма подписки скоро заработает');
    });
  }

  function syncCatalogFilters() {
    $$('[data-filter-category]').forEach(control => {
      const active = control.dataset.filterCategory === state.filters.category;
      control.classList.toggle('is-active', active);
      if (control.matches('button')) control.setAttribute('aria-pressed', String(active));
      else if (active) control.setAttribute('aria-current', 'true');
      else control.removeAttribute('aria-current');
    });
    $$('button[data-size], button[data-color]').forEach(button => {
      const active = button.hasAttribute('data-size') ? state.filters.sizes.has(button.dataset.size) : state.filters.colors.has(button.dataset.color);
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', String(active));
    });
    const selectedCount = (state.filters.category === 'all' ? 0 : 1) + state.filters.sizes.size + state.filters.colors.size;
    $$('[data-filter-open]').forEach(button => {
      const badge = $('i', button);
      if (badge) {
        badge.textContent = selectedCount;
        badge.setAttribute('aria-hidden', 'true');
      }
      button.setAttribute('aria-label', selectedCount ? `Фильтры, выбрано ${selectedCount}` : 'Фильтры');
    });
  }

  function setupCatalogFilters() {
    if (!$('[data-product-grid="catalog"]')) return;

    const params = new URLSearchParams(location.search);
    const category = params.get('category');
    const query = params.get('q');
    if (category === 'hoodie' || category === 'tee') state.filters.category = category;
    if (query) state.filters.query = query;
    const searchInput = $('#site-search');
    if (searchInput) searchInput.value = query || '';
    if (params.has('q')) {
      let robots = $('meta[name="robots"]');
      if (!robots) {
        robots = document.createElement('meta');
        robots.name = 'robots';
        document.head.append(robots);
      }
      robots.content = 'noindex, follow';
    }
    syncCatalogFilters();

    $$('[data-filter-category]').forEach(button => {
      button.addEventListener('click', () => {
        state.filters.category = button.dataset.filterCategory;
        syncCatalogFilters();
        renderProducts();
        if (filterViewport.matches) closeOverlay($('[data-filter-panel]'));
      });
    });

    $$('button[data-size]').forEach(button => {
      button.addEventListener('click', () => {
        const size = button.dataset.size;
        button.classList.toggle('is-active');
        if (button.classList.contains('is-active')) state.filters.sizes.add(size);
        else state.filters.sizes.delete(size);
        syncCatalogFilters();
        renderProducts();
      });
    });

    $$('button[data-color]').forEach(button => {
      button.addEventListener('click', () => {
        const color = button.dataset.color;
        button.classList.toggle('is-active');
        if (button.classList.contains('is-active')) state.filters.colors.add(color);
        else state.filters.colors.delete(color);
        syncCatalogFilters();
        renderProducts();
      });
    });

    $('[data-sort]')?.addEventListener('change', event => {
      state.filters.sort = event.currentTarget.value;
      renderProducts();
    });

    $$('[data-filter-reset]').forEach(button => button.addEventListener('click', resetFilters));
    $('[data-filter-open]')?.addEventListener('click', () => openOverlay($('[data-filter-panel]')));
    $('[data-filter-close]')?.addEventListener('click', () => closeOverlay($('[data-filter-panel]')));
  }

  function resetFilters() {
    const focusWasInEmptyState = document.activeElement?.closest('[data-catalog-empty]');
    state.filters.category = 'all';
    state.filters.sizes.clear();
    state.filters.colors.clear();
    state.filters.sort = 'new';
    state.filters.query = '';
    syncCatalogFilters();
    if ($('[data-sort]')) $('[data-sort]').value = 'new';
    if ($('#site-search')) $('#site-search').value = '';
    renderProducts();
    if (focusWasInEmptyState) {
      const count = $('[data-results-count]');
      count?.setAttribute('tabindex', '-1');
      count?.focus({ preventScroll: true });
    }
  }

  function setupCustomCursor() {
    if (matchMedia('(pointer: coarse)').matches) return;
    const dot = $('.cursor-dot');
    const ring = $('.cursor-ring');
    if (!dot || !ring) return;

    let x = -100;
    let y = -100;
    let rx = -100;
    let ry = -100;
    document.body.classList.add('has-custom-cursor');
    window.addEventListener('pointermove', event => { x = event.clientX; y = event.clientY; });
    const tick = () => {
      rx += (x - rx) * .14;
      ry += (y - ry) * .14;
      dot.style.transform = `translate(${x - 2.5}px, ${y - 2.5}px)`;
      ring.style.transform = `translate(${rx - ring.offsetWidth / 2}px, ${ry - ring.offsetHeight / 2}px)`;
      requestAnimationFrame(tick);
    };
    tick();
    document.addEventListener('pointerover', event => {
      if (event.target.closest('a, button, input, select, .product-card')) document.body.classList.add('cursor-hover');
    });
    document.addEventListener('pointerout', event => {
      if (event.target.closest('a, button, input, select, .product-card')) document.body.classList.remove('cursor-hover');
    });
  }

  function setupImageHoverSwap() {
    document.addEventListener('pointerover', event => {
      const card = event.target.closest('.product-card');
      if (!card || matchMedia('(pointer: coarse)').matches) return;
      const product = PRODUCTS.find(item => item.id === card.dataset.productId);
      const image = $('.product-card__image', card);
      if (product?.altImage && image && image.src !== new URL(product.altImage, location.href).href) {
        image.dataset.originalSrc = product.image;
        image.src = product.altImage;
      }
    });
    document.addEventListener('pointerout', event => {
      const card = event.target.closest('.product-card');
      if (!card || card.contains(event.relatedTarget)) return;
      const image = $('.product-card__image', card);
      if (image?.dataset.originalSrc) image.src = image.dataset.originalSrc;
    });
  }

  function setupPreloader() {
    const ready = () => {
      document.body.classList.add('is-ready', 'is-loaded');
      const preloader = document.querySelector('[data-preloader]');
      preloader?.remove();
    };
    requestAnimationFrame(ready);
  }

  setupPreloader();
  setupOverlayAccessibility();
  setupHeader();
  setupMenu();
  setupSearch();
  setupOverlays();
  setupCatalogFilters();
  renderProducts();
  bindProductCards(document);
  updateCartUI();
  observeReveals();
  setupHeroParallax();
  setupMagnetic();
  setupLookbook();
  setupNewsletter();
  setupCustomCursor();
  setupImageHoverSwap();
})();
