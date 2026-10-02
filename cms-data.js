/* Shared browser-only content for the MAHARA demo editor. */
(function () {
  'use strict';

  const clone = value => JSON.parse(JSON.stringify(value));
  const baseProducts = clone(window.MaharaCatalog.products);
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
        eyebrow: 'Первым увидеть новый дроп',
        title: 'Никакого спама',
        accent: 'Только вещи по делу'
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
    { id: 'newsletter', title: 'Подписка', description: 'Тексты блока подписки на новый дроп.', fields: [
      field('eyebrow', 'Подпись'), field('title', 'Заголовок'), field('accent', 'Акцентная строка')
    ] }
  ];

  function imageSource(value, fallback) {
    if (typeof value !== 'string') return fallback;
    if (/^assets\/images\/[\w.-]+\.(?:webp|png|jpe?g)$/i.test(value)) return value;
    if (value.length < 16000000 && /^data:image\/(?:jpeg|png|webp);base64,[a-z\d+/=]+$/i.test(value)) return value;
    return fallback;
  }

  function normalize(input) {
    const result = clone(defaults);
    if (!input || input.version !== 1) return result;
    for (const definition of sectionDefinitions) {
      const source = input.sections?.[definition.id];
      if (!source || typeof source !== 'object') continue;
      for (const { key, type } of definition.fields) {
        const original = result.sections[definition.id][key];
        if (type === 'image') result.sections[definition.id][key] = imageSource(source[key], original);
        else if (typeof source[key] === 'string') result.sections[definition.id][key] = source[key].trim().slice(0, type === 'textarea' ? 5000 : 200);
      }
    }
    for (const product of result.products) {
      const source = Array.isArray(input.products) && input.products.find(item => item?.id === product.id);
      if (!source) continue;
      for (const key of ['title', 'subtitle', 'description', 'label']) {
        if (typeof source[key] === 'string') product[key] = source[key].trim().slice(0, key === 'description' ? 5000 : 200);
      }
      const price = Number(source.price);
      if (Number.isFinite(price) && price >= 0 && price <= 10000000) product.price = Math.round(price);
      for (const key of ['image', 'altImage']) product[key] = imageSource(source[key], product[key]);
      if (Array.isArray(source.sizes)) {
        const sizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'].filter(size => source.sizes.includes(size));
        if (sizes.length) product.sizes = sizes;
      }
    }
    return result;
  }

  let database;
  function openDatabase() {
    if (!database) database = new Promise((resolve, reject) => {
      const request = indexedDB.open('mahara-demo-cms', 1);
      request.onupgradeneeded = () => request.result.createObjectStore('content');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
      request.onblocked = () => reject(new Error('Закройте другие вкладки демо и попробуйте снова.'));
    });
    return database;
  }

  async function readRecord() {
    const db = await openDatabase();
    return new Promise((resolve, reject) => {
      const transaction = db.transaction('content', 'readonly');
      const request = transaction.objectStore('content').get('site');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
      transaction.onabort = () => reject(transaction.error);
    });
  }

  async function writeRecord(value, remove = false) {
    const db = await openDatabase();
    await new Promise((resolve, reject) => {
      const transaction = db.transaction('content', 'readwrite');
      const store = transaction.objectStore('content');
      if (remove) store.delete('site');
      else store.put(value, 'site');
      transaction.oncomplete = resolve;
      transaction.onerror = () => reject(transaction.error);
      transaction.onabort = () => reject(transaction.error);
    });
  }

  async function load() {
    return normalize(await readRecord());
  }

  const channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel('mahara-demo-content') : null;
  if (channel) channel.onmessage = () => window.dispatchEvent(new Event('mahara:content-updated'));

  async function save(content) {
    const value = normalize(content);
    await writeRecord(value);
    window.dispatchEvent(new CustomEvent('mahara:content-saved', { detail: value }));
    channel?.postMessage('saved');
    return clone(value);
  }

  async function reset() {
    await writeRecord(null, true);
    const value = clone(defaults);
    window.dispatchEvent(new CustomEvent('mahara:content-saved', { detail: value }));
    channel?.postMessage('reset');
    return value;
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

  window.MaharaCMS = { getDefaults: () => clone(defaults), sectionDefinitions, load, save, reset, apply };
})();
