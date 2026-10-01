(() => {
  'use strict';

  const PRODUCTS = [
    {
      id: 'hoodie-black',
      title: 'Худи MAHARA Black',
      subtitle: 'Embroidery / Oversize',
      category: 'hoodie',
      color: 'black',
      price: 16900,
      image: 'assets/images/black-hoodie.webp',
      altImage: 'assets/images/black-hoodie-woman.webp',
      label: 'New drop',
      sizes: ['S', 'M', 'L', 'XL', 'XXL'],
      description: 'Чёрное худи свободного кроя из плотного хлопка; авторская вышивка с архитектурными символами, мягкая изнанка и глубокий капюшон'
    },
    {
      id: 'hoodie-green',
      title: 'Худи MAHARA Green',
      subtitle: 'Embroidery / Oversize',
      category: 'hoodie',
      color: 'green',
      price: 16900,
      image: 'assets/images/green-hoodie.webp',
      altImage: 'assets/images/green-hoodie-back.webp',
      label: 'New drop',
      sizes: ['S', 'M', 'L', 'XL', 'XXL'],
      description: 'Тёмно-зелёное худи с вышивкой по всей поверхности; плотная ткань держит форму, а объёмный силуэт подходит для многослойных образов'
    },
    {
      id: 'tee-slate',
      title: 'Футболка MAHARA Slate',
      subtitle: 'Embroidery / Heavy cotton',
      category: 'tee',
      color: 'slate',
      price: 7900,
      image: 'assets/images/slate-tee.webp',
      altImage: 'assets/images/slate-tee-back.webp',
      label: 'New drop',
      sizes: ['S', 'M', 'L', 'XL', 'XXL'],
      description: 'Футболка из плотного хлопка со свободной посадкой и небольшой вышивкой с российскими архитектурными мотивами'
    },
    {
      id: 'tee-burgundy',
      title: 'Футболка Last Supper Burgundy',
      subtitle: 'Art print / Heavy cotton',
      category: 'tee',
      color: 'burgundy',
      price: 6900,
      image: 'assets/images/burgundy-tee-angle.webp',
      altImage: 'assets/images/burgundy-tee.webp',
      label: 'Bestseller',
      sizes: ['S', 'M', 'L', 'XL'],
      description: 'Бордовая футболка с крупным арт-принтом; плотный хлопок, свободный силуэт и изображение, которое считывается как самостоятельное высказывание'
    },
    {
      id: 'tee-white',
      title: 'Футболка Last Supper White',
      subtitle: 'Art print / Heavy cotton',
      category: 'tee',
      color: 'white',
      price: 6900,
      image: 'assets/images/white-last-supper.webp',
      altImage: 'assets/images/green-tee-close.webp',
      label: 'New drop',
      sizes: ['S', 'M', 'L', 'XL'],
      description: 'Белая футболка с контрастным арт-принтом, плотным воротом и свободной посадкой; базовый цвет делает графику главным акцентом'
    },
    {
      id: 'tee-black',
      title: 'Футболка Last Supper Black',
      subtitle: 'Art print / Heavy cotton',
      category: 'tee',
      color: 'black',
      price: 6900,
      image: 'assets/images/black-last-supper.webp',
      altImage: 'assets/images/white-last-supper.webp',
      label: 'Limited',
      sizes: ['M', 'L', 'XL', 'XXL'],
      description: 'Чёрная футболка из плотного хлопка с крупным принтом; лимитированная модель из текущего дропа'
    },
    {
      id: 'tee-green',
      title: 'Футболка Last Supper Green',
      subtitle: 'Art print / Heavy cotton',
      category: 'tee',
      color: 'green',
      price: 6900,
      image: 'assets/images/green-tee.webp',
      altImage: 'assets/images/green-tee-seated.webp',
      label: 'New color',
      sizes: ['S', 'M', 'L', 'XL'],
      description: 'Глубокий зелёный оттенок, свободная посадка и крупный арт-принт; футболка работает и как акцент, и как часть спокойного образа'
    },
    {
      id: 'hoodie-black-female',
      title: 'Худи MAHARA Black II',
      subtitle: 'Unisex / Embroidery',
      category: 'hoodie',
      color: 'black',
      price: 16900,
      image: 'assets/images/black-hoodie-woman.webp',
      altImage: 'assets/images/black-hoodie.webp',
      label: 'Unisex',
      sizes: ['XS', 'S', 'M', 'L', 'XL'],
      description: 'Унисекс-худи с расслабленной посадкой, объёмным капюшоном и вышивкой по всей поверхности; хорошо сидит на разном типе фигуры'
    }
  ];

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

  function productCard(product, { featured = false } = {}) {
    const sizes = product.sizes.map((size, index) =>
      `<button type="button" data-size-choice="${size}" class="${index === 1 ? 'is-active' : ''}" aria-label="Размер ${size}">${size}</button>`
    ).join('');

    return `
      <article class="product-card reveal" data-product-id="${product.id}" data-category="${product.category}" data-color="${product.color}">
        <div class="product-card__media">
          <span class="product-card__badge">${product.label}</span>
          <button class="product-card__favorite" type="button" data-favorite aria-label="Добавить в избранное">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 4.7a5.6 5.6 0 0 0-7.9 0L12 5.6l-.9-.9a5.6 5.6 0 1 0-7.9 7.9l.9.9L12 21l7.9-7.5.9-.9a5.6 5.6 0 0 0 0-7.9Z"/></svg>
          </button>
          <img class="product-card__image" src="${product.image}" alt="${product.title}" loading="lazy" />
          <button class="product-card__quick" type="button" data-quick-open>Быстрый просмотр</button>
        </div>
        <div class="product-card__body">
          <span class="product-card__label">${product.category === 'hoodie' ? 'Худи / Вышивка' : 'Футболка / Арт'}</span>
          <h3>${product.title}</h3>
          <p class="product-card__subtitle">${product.subtitle}</p>
          <strong class="product-card__price">${formatPrice(product.price)}</strong>
          <div class="product-card__sizes" aria-label="Выберите размер">${sizes}</div>
          <button class="product-card__add" type="button" data-add-cart>В корзину</button>
        </div>
      </article>`;
  }

  function renderProducts() {
    $$('[data-product-grid]').forEach(grid => {
      const type = grid.dataset.productGrid;
      const products = type === 'featured' ? PRODUCTS.slice(0, 4) : filteredProducts();
      grid.innerHTML = products.map(product => productCard(product, { featured: type === 'featured' })).join('');
      if (type === 'catalog') {
        const count = $('[data-results-count]');
        const empty = $('[data-catalog-empty]');
        if (count) count.textContent = pluralProducts(products.length);
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
    $$('.product-card', root).forEach(card => {
      const product = PRODUCTS.find(item => item.id === card.dataset.productId);
      if (!product) return;

      $('[data-favorite]', card)?.addEventListener('click', event => {
        event.currentTarget.classList.toggle('is-active');
        showToast(event.currentTarget.classList.contains('is-active') ? 'Добавлено в избранное' : 'Удалено из избранного');
      });

      $$('[data-size-choice]', card).forEach(button => {
        button.addEventListener('click', () => {
          $$('[data-size-choice]', card).forEach(item => item.classList.remove('is-active'));
          button.classList.add('is-active');
        });
      });

      $('[data-add-cart]', card)?.addEventListener('click', () => {
        const size = $('[data-size-choice].is-active', card)?.dataset.sizeChoice || product.sizes[0];
        addToCart(product.id, size);
      });

      $('[data-quick-open]', card)?.addEventListener('click', () => openQuickView(product));
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
    const caption = $('[data-cart-caption]');
    const totalNode = $('[data-cart-total]');
    const itemsNode = $('[data-cart-items]');
    const emptyNode = $('[data-cart-empty]');
    const footer = $('[data-cart-footer]');

    if (caption) caption.textContent = pluralProducts(count);
    if (!itemsNode) return;

    const total = state.cart.reduce((sum, item) => {
      const product = PRODUCTS.find(p => p.id === item.id);
      return sum + (product?.price || 0) * item.qty;
    }, 0);
    if (totalNode) totalNode.textContent = formatPrice(total);

    itemsNode.innerHTML = state.cart.map((item, index) => {
      const product = PRODUCTS.find(p => p.id === item.id);
      if (!product) return '';
      return `
        <div class="cart-item">
          <img src="${product.image}" alt="${product.title}" />
          <div class="cart-item__copy">
            <strong>${product.title}</strong>
            <span>Размер ${item.size} · ${item.qty} шт</span>
            <b>${formatPrice(product.price * item.qty)}</b>
          </div>
          <button class="cart-item__remove" type="button" data-cart-remove="${index}" aria-label="Удалить">×</button>
        </div>`;
    }).join('');

    $$('[data-cart-remove]', itemsNode).forEach(button => {
      button.addEventListener('click', () => removeCartItem(Number(button.dataset.cartRemove)));
    });

    const hasItems = count > 0;
    if (emptyNode) emptyNode.style.display = hasItems ? 'none' : 'grid';
    if (footer) footer.style.display = hasItems ? 'block' : 'none';
  }

  function openCart() {
    document.body.classList.remove('menu-open', 'modal-open', 'search-open');
    document.body.classList.add('drawer-open');
    $('[data-cart-drawer]')?.setAttribute('aria-hidden', 'false');
  }

  function closeCart() {
    document.body.classList.remove('drawer-open');
    $('[data-cart-drawer]')?.setAttribute('aria-hidden', 'true');
  }

  function openQuickView(product) {
    state.quickProduct = product;
    state.quickSize = product.sizes[0];
    const modal = $('[data-quick-view]');
    if (!modal) return;

    $('[data-quick-image]', modal).src = product.image;
    $('[data-quick-image]', modal).alt = product.title;
    $('[data-quick-title]', modal).textContent = product.title;
    $('[data-quick-description]', modal).textContent = product.description;
    $('[data-quick-price]', modal).textContent = formatPrice(product.price);
    $('[data-quick-label]', modal).textContent = product.label;

    const sizesNode = $('[data-quick-sizes]', modal);
    sizesNode.innerHTML = product.sizes.map((size, index) =>
      `<button type="button" class="${index === 0 ? 'is-active' : ''}" data-quick-size="${size}">${size}</button>`
    ).join('');
    $$('[data-quick-size]', sizesNode).forEach(button => {
      button.addEventListener('click', () => {
        $$('[data-quick-size]', sizesNode).forEach(item => item.classList.remove('is-active'));
        button.classList.add('is-active');
        state.quickSize = button.dataset.quickSize;
      });
    });

    document.body.classList.remove('drawer-open', 'menu-open', 'search-open');
    document.body.classList.add('modal-open');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeQuickView() {
    document.body.classList.remove('modal-open');
    $('[data-quick-view]')?.setAttribute('aria-hidden', 'true');
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
      const open = !document.body.classList.contains('menu-open');
      document.body.classList.toggle('menu-open', open);
      document.body.classList.remove('drawer-open', 'modal-open', 'search-open');
      toggle.setAttribute('aria-expanded', String(open));
      menu.setAttribute('aria-hidden', String(!open));
    });

    $$('a', menu).forEach(link => link.addEventListener('click', () => {
      document.body.classList.remove('menu-open');
      toggle.setAttribute('aria-expanded', 'false');
      menu.setAttribute('aria-hidden', 'true');
    }));
  }

  function setupSearch() {
    const panel = $('[data-search-panel]');
    if (!panel) return;
    $$('.search-trigger').forEach(button => button.addEventListener('click', () => {
      document.body.classList.remove('menu-open', 'drawer-open', 'modal-open');
      document.body.classList.add('search-open');
      panel.setAttribute('aria-hidden', 'false');
      setTimeout(() => $('#site-search')?.focus(), 150);
    }));
    $('[data-search-close]')?.addEventListener('click', closeSearch);
  }

  function closeSearch() {
    document.body.classList.remove('search-open');
    $('[data-search-panel]')?.setAttribute('aria-hidden', 'true');
  }

  function setupOverlays() {
    $$('.cart-trigger').forEach(button => button.addEventListener('click', openCart));
    $('.cart-close')?.addEventListener('click', closeCart);
    $('[data-drawer-overlay]')?.addEventListener('click', () => {
      closeCart();
      closeQuickView();
    });
    $('.quick-view__close')?.addEventListener('click', closeQuickView);
    $('.quick-add')?.addEventListener('click', () => {
      if (!state.quickProduct) return;
      addToCart(state.quickProduct.id, state.quickSize || state.quickProduct.sizes[0]);
      closeQuickView();
      setTimeout(openCart, 280);
    });
    document.addEventListener('keydown', event => {
      if (event.key !== 'Escape') return;
      closeCart();
      closeQuickView();
      closeSearch();
      document.body.classList.remove('menu-open', 'filter-open');
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

    const goTo = index => {
      requestedIndex = Math.max(0, Math.min(index, stops.length - 1));
      slider.scrollTo({ left: stops[requestedIndex], behavior: reducedMotion.matches ? 'auto' : 'smooth' });
      syncControls();
    };

    const finishScroll = () => {
      requestedIndex = null;
      syncControls();
    };

    const measure = () => {
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
      requestedIndex = null;

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

      slider.scrollTo({ left: stops[nearestIndex()], behavior: 'auto' });
      syncControls();
    };

    const scheduleMeasure = () => {
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
      clearTimeout(scrollTimer);
      scrollTimer = setTimeout(finishScroll, 160);
    }, { passive: true });
    slider.addEventListener('scrollend', finishScroll);
    slider.addEventListener('wheel', () => { requestedIndex = null; }, { passive: true });

    slider.addEventListener('pointerdown', event => {
      requestedIndex = null;
      if (event.pointerType !== 'mouse' || event.button !== 0) return;
      mouseDrag = { pointerId: event.pointerId, startX: event.clientX, startScroll: slider.scrollLeft, moved: false };
      slider.setPointerCapture(event.pointerId);
      slider.focus({ preventScroll: true });
    });

    slider.addEventListener('pointermove', event => {
      if (!mouseDrag || event.pointerId !== mouseDrag.pointerId) return;
      const distance = event.clientX - mouseDrag.startX;
      if (!mouseDrag.moved && Math.abs(distance) <= 5) return;
      mouseDrag.moved = true;
      slider.classList.add('is-dragging');
      event.preventDefault();
      slider.scrollLeft = Math.max(0, Math.min(mouseDrag.startScroll - distance, maxScroll));
    });

    const finishDrag = event => {
      if (!mouseDrag || event.pointerId !== mouseDrag.pointerId) return;
      const moved = mouseDrag.moved;
      mouseDrag = null;
      const target = nearestIndex();
      slider.classList.remove('is-dragging');
      if (slider.hasPointerCapture(event.pointerId)) slider.releasePointerCapture(event.pointerId);
      if (moved) goTo(target);
    };
    slider.addEventListener('pointerup', finishDrag);
    slider.addEventListener('pointercancel', finishDrag);
    slider.addEventListener('lostpointercapture', finishDrag);
    slider.addEventListener('dragstart', event => event.preventDefault());

    window.addEventListener('resize', scheduleMeasure, { passive: true });
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(scheduleMeasure).observe(slider);
    measure();
  }

  function setupNewsletter() {
    $('[data-newsletter-form]')?.addEventListener('submit', event => {
      event.preventDefault();
      const input = $('input', event.currentTarget);
      showToast(`Готово — ${input.value} в списке`);
      event.currentTarget.reset();
    });
  }

  function setupCatalogFilters() {
    if (!$('[data-product-grid="catalog"]')) return;

    const params = new URLSearchParams(location.search);
    const category = params.get('category');
    const query = params.get('q');
    if (category === 'hoodie' || category === 'tee') state.filters.category = category;
    if (query) state.filters.query = query;

    const syncCategories = value => {
      $$('[data-filter-category]').forEach(button => button.classList.toggle('is-active', button.dataset.filterCategory === value));
    };
    syncCategories(state.filters.category);

    $$('[data-filter-category]').forEach(button => {
      button.addEventListener('click', () => {
        state.filters.category = button.dataset.filterCategory;
        syncCategories(state.filters.category);
        renderProducts();
        if (innerWidth <= 700) document.body.classList.remove('filter-open');
      });
    });

    $$('[data-size]').forEach(button => {
      button.addEventListener('click', () => {
        const size = button.dataset.size;
        button.classList.toggle('is-active');
        if (button.classList.contains('is-active')) state.filters.sizes.add(size);
        else state.filters.sizes.delete(size);
        renderProducts();
      });
    });

    $$('[data-color]').forEach(button => {
      button.addEventListener('click', () => {
        const color = button.dataset.color;
        button.classList.toggle('is-active');
        if (button.classList.contains('is-active')) state.filters.colors.add(color);
        else state.filters.colors.delete(color);
        renderProducts();
      });
    });

    $('[data-sort]')?.addEventListener('change', event => {
      state.filters.sort = event.currentTarget.value;
      renderProducts();
    });

    $$('[data-filter-reset]').forEach(button => button.addEventListener('click', resetFilters));
    $('[data-filter-open]')?.addEventListener('click', () => document.body.classList.add('filter-open'));
    $('[data-filter-close]')?.addEventListener('click', () => document.body.classList.remove('filter-open'));
  }

  function resetFilters() {
    state.filters.category = 'all';
    state.filters.sizes.clear();
    state.filters.colors.clear();
    state.filters.sort = 'new';
    state.filters.query = '';
    $$('[data-filter-category]').forEach(button => button.classList.toggle('is-active', button.dataset.filterCategory === 'all'));
    $$('[data-size], [data-color]').forEach(button => button.classList.remove('is-active'));
    if ($('[data-sort]')) $('[data-sort]').value = 'new';
    renderProducts();
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
      window.setTimeout(() => preloader?.remove(), 750);
    };
    if (document.readyState === 'complete') setTimeout(ready, 250);
    else window.addEventListener('load', () => setTimeout(ready, 350), { once: true });
    setTimeout(ready, 1800);
  }

  setupPreloader();
  setupHeader();
  setupMenu();
  setupSearch();
  setupOverlays();
  setupCatalogFilters();
  renderProducts();
  updateCartUI();
  observeReveals();
  setupHeroParallax();
  setupMagnetic();
  setupLookbook();
  setupNewsletter();
  setupCustomCursor();
  setupImageHoverSwap();
})();
