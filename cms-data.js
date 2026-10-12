/* Shared content client for the MAHARA server and editor. */
(function () {
  'use strict';

  const clone = value => JSON.parse(JSON.stringify(value));
  const productFactLabels = {
    composition: 'Состав', care: 'Уход', manufacturer: 'Изготовитель и страна производства', measurements: 'Замеры и посадка'
  };
  const productFacts = Object.keys(productFactLabels);
  const baseProducts = clone(window.MaharaCatalog.products).map(product => ({
    ...product, ...Object.fromEntries(productFacts.map(key => [key, typeof product[key] === 'string' ? product[key].trim().slice(0, 2000) : '']))
  }));
  const defaults = {
    version: 1,
    sections: {
      hero: {
        eyebrow: 'Уличная культура · Российский дух',
        title: 'MAHARA',
        subtitle: 'MADE IN RUSSIA',
        description: 'Премиальная одежда с авторской вышивкой и арт‑принтами\nМалые тиражи для тех, кто не хочет выглядеть как все',
        button: 'Смотреть дроп',
        image: 'assets/images/hero-man.webp',
        secondaryImage: 'assets/images/hero-woman.webp'
      },
      campaign: {
        eyebrow: 'Campaign 001',
        title: 'Не просто одежда',
        accent: 'Часть разговора',
        description: 'Коллекция соединяет архитектуру, культурные символы и современный уличный силуэт\nВ каждой вещи — детали, которые считываются постепенно',
        button: 'Открыть lookbook',
        image: 'assets/images/green-tee-seated.webp'
      },
      story: {
        eyebrow: 'О бренде',
        title: 'Локальный бренд',
        accent: 'без провинциальности',
        lead: 'MAHARA создаёт одежду в России и говорит с миром на современном визуальном языке',
        description: 'Мы не пытаемся выпускать всё для всех\nВместо этого делаем небольшие коллекции, следим за посадкой, тканью и тем, как каждая деталь проживёт вместе с владельцем',
        image: 'assets/images/black-last-supper.webp',
        secondaryImage: 'assets/images/green-tee-close.webp'
      },
      newsletter: {
        eyebrow: 'Новые коллекции',
        title: 'Следите за дропами',
        accent: 'В наших социальных сетях'
      }
    },
    products: baseProducts
  };
  const field = (key, label, type = 'text') => ({ key, label, type });
  const sectionDefinitions = [
    { id: 'hero', title: 'Первый экран', description: 'Главный текст, фотографии и кнопка на главной.', fields: [
      field('eyebrow', 'Текст над заголовком'), field('title', 'Заголовок'), field('subtitle', 'Подзаголовок'),
      field('description', 'Описание', 'textarea'), field('button', 'Текст кнопки'),
      field('image', 'Фотография слева', 'image'), field('secondaryImage', 'Фотография справа', 'image')
    ] },
    { id: 'campaign', title: 'Кампания', description: 'Большой блок с фотографией коллекции.', fields: [
      field('eyebrow', 'Подпись'), field('title', 'Заголовок'), field('accent', 'Акцентная строка'),
      field('description', 'Описание', 'textarea'), field('button', 'Текст кнопки'), field('image', 'Фотография', 'image')
    ] },
    { id: 'story', title: 'О бренде', description: 'История MAHARA и фотографии бренда.', fields: [
      field('eyebrow', 'Подпись'), field('title', 'Заголовок'), field('accent', 'Акцентная строка'),
      field('lead', 'Вступление', 'textarea'), field('description', 'История', 'textarea'),
      field('image', 'Основная фотография', 'image'), field('secondaryImage', 'Вторая фотография', 'image')
    ] },
    { id: 'newsletter', title: 'Новости бренда', description: 'Тексты блока со ссылками на социальные сети. Email здесь не собирается.', fields: [
      field('eyebrow', 'Подпись'), field('title', 'Заголовок'), field('accent', 'Акцентная строка')
    ] }
  ];

  function imageSource(value, fallback) {
    if (typeof value !== 'string') return fallback;
    if (/^assets\/(?:images|uploads)\/[\w.-]+\.(?:webp|png|jpe?g)$/i.test(value)) return value;
    return fallback;
  }

  function normalize(input) {
    const result = clone(defaults);
    if (!input || input.version !== 1) return result;
    result.revision = Number.isInteger(input.revision) ? input.revision : 0;
    for (const definition of sectionDefinitions) {
      const source = input.sections?.[definition.id];
      if (!source || typeof source !== 'object') continue;
      for (const { key, type } of definition.fields) {
        const original = result.sections[definition.id][key];
        if (type === 'image') result.sections[definition.id][key] = imageSource(source[key], original);
        else if (typeof source[key] === 'string') result.sections[definition.id][key] = source[key].trim().slice(0, type === 'textarea' ? 5000 : 200);
      }
    }
    if (Array.isArray(input.products)) result.products = input.products.filter(source =>
      source && /^[a-z0-9][a-z0-9-]{0,79}$/.test(source.id) && ['hoodie', 'tee'].includes(source.category)
    ).map(source => {
      const product = clone(baseProducts.find(item => item.id === source.id) || baseProducts[0]);
      product.id = source.id;
      product.url = `product-${source.id}.html`;
      product.category = source.category;
      if (['black', 'green', 'burgundy', 'slate', 'white'].includes(source.color)) product.color = source.color;
      for (const key of ['width', 'height']) if (Number.isInteger(source[key]) && source[key] > 0 && source[key] <= 12000) product[key] = source[key];
      for (const key of ['title', 'subtitle', 'description', 'label']) {
        if (typeof source[key] === 'string') product[key] = source[key].trim().slice(0, key === 'description' ? 5000 : 200);
      }
      for (const key of productFacts) product[key] = typeof source[key] === 'string' ? source[key].trim().slice(0, 2000) : '';
      const price = Number(source.price);
      if (Number.isFinite(price) && price >= 0 && price <= 10000000) product.price = Math.round(price);
      for (const key of ['image', 'altImage']) product[key] = imageSource(source[key], product[key]);
      if (Array.isArray(source.sizes)) {
        const sizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'].filter(size => source.sizes.includes(size));
        if (sizes.length) product.sizes = sizes;
      }
      return product;
    });
    return result;
  }

  let csrfToken;
  async function request(url, options = {}) {
    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(data.error || data.message || (response.status === 401 ? 'Войдите в админку ещё раз.' : 'Сервер не сохранил изменения. Попробуйте ещё раз.'));
      error.status = response.status;
      if (response.status === 401) csrfToken = null;
      throw error;
    }
    return data;
  }

  async function load(options = {}) {
    const embedded = options.preferEmbedded && document.getElementById('mahara-content');
    if (embedded) {
      try { return normalize(JSON.parse(embedded.textContent)); } catch { /* Fall through to the API. */ }
    }
    return normalize(await request('/api/content'));
  }

  async function authorization() {
    if (!csrfToken) csrfToken = (await request('/api/admin/session')).csrfToken;
    return { 'X-CSRF-Token': csrfToken };
  }

  const channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel('mahara-content') : null;
  if (channel) channel.onmessage = () => window.dispatchEvent(new Event('mahara:content-updated'));

  async function save(content) {
    const value = normalize(await request('/api/admin/content', {
      method: 'PUT', headers: { ...await authorization(), 'Content-Type': 'application/json' }, body: JSON.stringify(content)
    }));
    window.dispatchEvent(new CustomEvent('mahara:content-saved', { detail: value }));
    channel?.postMessage('saved');
    return clone(value);
  }

  async function upload(file) {
    return request('/api/admin/uploads', {
      method: 'POST', headers: { ...await authorization(), 'Content-Type': file.type }, body: file
    });
  }

  function text(selector, value, multiline = false) {
    const element = document.querySelector(selector);
    if (!element) return;
    element.textContent = value;
    if (multiline) element.style.whiteSpace = 'pre-line';
  }

  function image(selector, value) {
    const element = document.querySelector(selector);
    if (element) element.src = value;
  }

  function heading(selector, title, accent) {
    const element = document.querySelector(selector);
    if (!element) return;
    const emphasis = document.createElement('em');
    emphasis.textContent = accent;
    element.replaceChildren(document.createTextNode(title), document.createElement('br'), emphasis);
  }

  function apply(content) {
    const value = normalize(content);
    window.MaharaCatalog.products.splice(0, window.MaharaCatalog.products.length, ...value.products);
    // Update server-rendered cards before script.js caches them for filtering.
    document.querySelectorAll('[data-product-grid]').forEach(grid => {
      const products = grid.dataset.productGrid === 'featured' ? value.products.slice(0, 4) : value.products;
      grid.innerHTML = products.map(window.MaharaCatalog.card).join('');
    });
    const { hero, campaign, story, newsletter } = value.sections;
    text('.hero__content > .eyebrow', hero.eyebrow);
    text('.hero-title__main', hero.title);
    const heroTitle = document.querySelector('.hero-title__main');
    if (heroTitle) heroTitle.dataset.title = hero.title;
    text('.hero-title__sub', hero.subtitle);
    text('.hero__copy', hero.description, true);
    text('.hero__actions .button--primary span', hero.button);
    image('.hero-photo--left img', hero.image);
    image('.hero-photo--right img', hero.secondaryImage);
    text('.campaign__content > .eyebrow', campaign.eyebrow);
    heading('.campaign__content h2', campaign.title, campaign.accent);
    text('.campaign__content > p:not(.eyebrow)', campaign.description, true);
    text('.campaign__content .button span', campaign.button);
    image('.campaign__media img', campaign.image);
    text('.story__copy > .eyebrow', story.eyebrow);
    heading('.story__copy h2', story.title, story.accent);
    text('.story__lead', story.lead, true);
    text('.story__copy > p:not(.eyebrow):not(.story__lead)', story.description, true);
    image('.story__picture--main img', story.image);
    image('.story__picture--float img', story.secondaryImage);
    text('.newsletter__inner > .eyebrow', newsletter.eyebrow);
    heading('.newsletter__inner h2', newsletter.title, newsletter.accent);

    const detail = document.querySelector('[data-product-detail]');
    const product = detail && value.products.find(item => item.id === detail.dataset.productId);
    if (product) {
      delete detail.dataset.productBound;
      text('#product-title', product.title);
      text('.product-main .breadcrumbs [aria-current="page"]', product.title);
      text('.product-detail__description', product.description);
      text('.product-detail__price', `${new Intl.NumberFormat('ru-RU').format(product.price)} ₽`);
      text('.product-detail__facts > div:nth-child(2) dd', product.sizes.join(', '));
      text('.product-detail__facts > div:nth-child(3) dd', product.subtitle);
      const facts = detail.querySelector('.product-detail__facts');
      if (facts) for (const key of productFacts) {
        let fact = facts.querySelector(`[data-product-fact="${key}"]`);
        if (!product[key]) { fact?.remove(); continue; }
        if (!fact) {
          fact = document.createElement('div');
          fact.dataset.productFact = key;
          fact.append(document.createElement('dt'), document.createElement('dd'));
          facts.append(fact);
        }
        fact.querySelector('dt').textContent = productFactLabels[key];
        fact.querySelector('dd').textContent = product[key];
        fact.querySelector('dd').style.whiteSpace = 'pre-line';
      }
      const gallery = detail.querySelector('.product-detail__gallery');
      if (gallery) {
        const photos = product.image === product.altImage ? [product.image] : [product.image, product.altImage];
        gallery.replaceChildren(...photos.map((source, index) => {
          const picture = document.createElement('img');
          picture.src = source;
          picture.alt = `${product.title}${index ? ' — другой ракурс' : ''}`;
          picture.width = product.width;
          picture.height = product.height;
          picture.decoding = 'async';
          picture.loading = index ? 'lazy' : 'eager';
          if (!index) picture.fetchPriority = 'high';
          return picture;
        }));
      }
      const sizes = detail.querySelector('.product-card__sizes');
      if (sizes) sizes.replaceChildren(...product.sizes.map((size, index) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = size;
        button.dataset.sizeChoice = size;
        button.classList.toggle('is-active', index === 0);
        button.setAttribute('aria-pressed', String(index === 0));
        button.setAttribute('aria-label', `Размер ${size}`);
        return button;
      }));
      const addButton = detail.querySelector('[data-add-cart]');
      if (addButton) {
        const replacement = addButton.cloneNode(true);
        replacement.setAttribute('aria-label', `Добавить ${product.title} в корзину`);
        addButton.replaceWith(replacement);
      }
    }
    return value;
  }

  window.MaharaCMS = { getDefaults: () => clone(defaults), sectionDefinitions, load, save, upload, apply };
})();
