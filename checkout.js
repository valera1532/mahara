(() => {
  'use strict';
  document.documentElement.classList.add('js');

  const $ = selector => document.querySelector(selector);
  const CART_KEY = 'maharaCart';
  const ORDER_KEY = 'maharaCheckoutOrder:';
  const ATTEMPT_KEY = 'maharaCheckoutAttempt';
  const POLL_INTERVAL = 5000;
  const POLL_WINDOW = 10 * 60 * 1000;
  const FINAL_STATUSES = new Set(['paid', 'canceled', 'cancelled', 'expired', 'failed', 'refunded', 'partial_refunded']);
  const PENDING_STATUSES = new Set(['pending', 'payment_pending', 'awaiting_payment', 'new', 'refund_pending']);
  const bankDomains = ['tbank.ru', 'tinkoff.ru', 'qr.nspk.ru'];
  const state = { config: null, quote: null, items: [], busy: false, pending: null, orderId: null, record: null, order: null, poll: null, pollStarted: 0, checking: false, quoteGeneration: 0, qrSource: null, qrValue: null, retry: null };
  const shipping = { config: null, city: null, cities: [], cityQuery: '', activeCity: -1, points: [], filteredPoints: [], activePoint: -1, quote: null, generation: 0, searchGeneration: 0, searchController: null, pointGeneration: 0, busy: false, searchTimer: null, expiryTimer: null };
  const money = amount => new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: amount % 100 ? 2 : 0 }).format(amount / 100);
  const amountValid = value => Number.isSafeInteger(value) && value >= 0;
  const idValid = value => typeof value === 'string' && /^[a-zA-Z0-9_-]{1,100}$/.test(value);
  const hide = (selector, value = true) => { $(selector).hidden = value; };

  class CheckoutError extends Error {
    constructor(message, status = 0, orderNotCreated = false) { super(message); this.status = status; this.orderNotCreated = orderNotCreated; }
  }

  async function api(path, options = {}) {
    const controller = new AbortController();
    const externalAbort = () => controller.abort();
    options.signal?.addEventListener('abort', externalAbort, { once: true });
    if (options.signal?.aborted) controller.abort();
    const timeout = setTimeout(() => controller.abort(), options.method === 'POST' && path.endsWith('/orders') ? 45000 : 25000);
    try {
      const response = await fetch(path, { credentials: 'same-origin', cache: 'no-store', redirect: 'error', ...options, headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}), ...options.headers }, signal: controller.signal });
      let data;
      try { data = await response.json(); } catch { throw new CheckoutError('Не удалось получить ответ. Попробуйте ещё раз.', response.status); }
      if (!response.ok) {
        const message = typeof data.error === 'string' ? data.error : typeof data.message === 'string' ? data.message : 'Не удалось выполнить запрос. Попробуйте ещё раз.';
        throw new CheckoutError(message, response.status, data.orderNotCreated === true && [400, 409, 422].includes(response.status));
      }
      if (!data || typeof data !== 'object') throw new CheckoutError('Не удалось получить данные заказа.');
      return data;
    } catch (error) {
      if (error instanceof CheckoutError) throw error;
      throw new CheckoutError(error.name === 'AbortError' ? 'Ответ задерживается. Проверьте соединение и попробуйте ещё раз.' : 'Нет связи с магазином. Проверьте соединение и попробуйте ещё раз.');
    } finally { clearTimeout(timeout); options.signal?.removeEventListener('abort', externalAbort); }
  }

  function showError(message, retry = null) {
    $('[data-error]').textContent = message;
    hide('[data-error]', false);
    state.retry = retry;
    hide('[data-retry]', !retry);
  }

  function clearError() { hide('[data-error]'); hide('[data-retry]'); state.retry = null; }

  function readCart() {
    let input;
    try { input = JSON.parse(localStorage.getItem(CART_KEY) || '[]'); }
    catch { throw new CheckoutError('Не удалось прочитать корзину. Вернитесь в каталог и проверьте выбранные товары.'); }
    if (!Array.isArray(input) || input.length > 100) throw new CheckoutError('Проверьте содержимое корзины в каталоге.');
    const merged = new Map();
    for (const item of input) {
      if (!item || typeof item.id !== 'string' || !/^[a-z0-9][a-z0-9-]{0,79}$/.test(item.id) || !['XS', 'S', 'M', 'L', 'XL', 'XXL'].includes(item.size) || !Number.isInteger(item.qty) || item.qty < 1 || item.qty > 20) throw new CheckoutError('Проверьте товары, размеры и количество в корзине. Максимум — 20 штук одного размера.');
      const key = `${item.id}:${item.size}`;
      const qty = (merged.get(key)?.qty || 0) + item.qty;
      if (qty > 20) throw new CheckoutError('Максимум — 20 штук одного товара и размера.');
      merged.set(key, { id: item.id, size: item.size, qty });
    }
    return [...merged.values()];
  }

  function imageUrl(value) {
    if (typeof value !== 'string') return null;
    try {
      const url = new URL(value, location.origin);
      return url.origin === location.origin && /^\/assets\/(?:images|uploads)\/[a-zA-Z0-9][a-zA-Z0-9._-]*\.(?:webp|png|jpe?g)$/.test(url.pathname) ? url.href : null;
    } catch { return null; }
  }

  function bankUrl(value) {
    if (typeof value !== 'string') return null;
    try {
      const url = new URL(value);
      return url.protocol === 'https:' && !url.username && !url.password && !url.port && bankDomains.some(domain => url.hostname === domain || url.hostname.endsWith(`.${domain}`)) ? url.href : null;
    } catch { return null; }
  }

  function legalUrl(value) {
    if (typeof value !== 'string' || !value.trim()) return null;
    try {
      const url = new URL(value, location.origin);
      return !url.username && !url.password && ((url.origin === location.origin && ['http:', 'https:'].includes(url.protocol)) || (url.protocol === 'https:' && !url.port)) && !(url.origin === location.origin && url.pathname === '/checkout.html') ? url.href : null;
    } catch { return null; }
  }

  function configure() {
    const config = state.config;
    const terms = legalUrl(config?.legal?.termsUrl);
    const privacy = legalUrl(config?.legal?.privacyUrl);
    const shippingReady = ['fixed', 'cdek_receiver'].includes(config?.delivery?.mode);
    const available = config?.enabled === true && config.currency === 'RUB' && shippingReady && terms && privacy;
    const consent = $('[data-consent-copy]');
    consent.replaceChildren();
    if (terms && privacy) {
      consent.append('Принимаю ');
      const link = document.createElement('a');
      link.href = terms; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.textContent = 'условия заказа';
      consent.append(link, '.');
    } else consent.textContent = 'Условия оформления заказа временно недоступны.';
    const privacyCopy = $('[data-privacy-copy]');
    privacyCopy.replaceChildren('Данные используются для оформления и исполнения заказа. ');
    const policy = document.createElement('a');
    policy.href = privacy || '/privacy.html'; policy.target = '_blank'; policy.rel = 'noopener noreferrer'; policy.textContent = 'Политика обработки персональных данных';
    privacyCopy.append(policy, '.');
    $('#accepted-terms').disabled = !available;
    const notice = $('[data-availability]');
    notice.hidden = Boolean(available);
    notice.textContent = !available && typeof config?.message === 'string' && config.message.trim() ? config.message : 'Оформление заказа временно недоступно. Пожалуйста, попробуйте позже.';
    if (config?.delivery?.mode === 'cdek_receiver') {
      $('[data-delivery-note]').textContent = 'Выберите город и пункт выдачи. Стоимость доставки покажем заранее; оплатить её нужно СДЭК при получении.';
      $('[data-summary-note]').textContent = 'Сейчас вы оплачиваете товары. Доставка СДЭК оплачивается отдельно при получении.';
    } else {
      $('[data-delivery-note]').textContent = 'Укажите удобный пункт выдачи СДЭК.';
      $('[data-summary-note]').textContent = 'Стоимость и наличие проверяются перед оплатой.';
    }
    const receiver = config?.delivery?.mode === 'cdek_receiver';
    $('[data-total-label]').textContent = receiver ? 'К оплате сейчас' : 'Итого к оплате';
    hide('[data-shipping-disclaimer]', !receiver);
    hide('[data-shipping-picker]', !receiver); hide('[data-manual-point]', receiver); hide('[data-manual-address]', receiver);
    for (const selector of ['#delivery-point', '#delivery-address']) { $(selector).required = !receiver; $(selector).disabled = receiver; }
    $('#delivery-city').disabled = receiver && shipping.config?.enabled !== true;
    $('#delivery-city').autocomplete = receiver ? 'off' : 'address-level2';
    for (const [attribute, value] of [['role', 'combobox'], ['aria-autocomplete', 'list'], ['aria-controls', 'shipping-city-list'], ['aria-expanded', 'false']]) {
      if (receiver) $('#delivery-city').setAttribute(attribute, value);
      else $('#delivery-city').removeAttribute(attribute);
    }
    $('#city-help').textContent = receiver ? 'Введите название города, посёлка или станицы и выберите подсказку.' : 'Укажите населённый пункт доставки.';
    if (receiver && shipping.config?.enabled !== true) shippingMessage('Расчёт доставки временно недоступен. Пожалуйста, попробуйте позже.');
    state.available = Boolean(available);
    updateSubmit();
  }

  function updateSubmit() {
    $('[data-submit]').disabled = state.busy || Boolean(state.pending) || !state.available || !state.quote || !$('#accepted-terms').checked || (state.config?.delivery?.mode === 'cdek_receiver' && !shippingValid());
    $('[data-submit-label]').textContent = state.busy ? 'Создаём заказ…' : state.quote ? `Оплатить ${money(state.quote.totalAmount)} через СБП` : 'Оплатить через СБП';
    $('[data-checkout-form]').setAttribute('aria-busy', String(state.busy));
  }

  function expiresAt(value) {
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T/.test(value)) return Date.parse(value) || 0;
    const expires = Number(value);
    return Number.isFinite(expires) && expires > 0 ? (expires < 1e12 ? expires * 1000 : expires) : 0;
  }

  function shippingValid() {
    return shipping.config?.enabled === true && shipping.city && shipping.quote && !shipping.busy && expiresAt(shipping.quote.expiresAt) > Date.now() && shipping.quote.point.code === $('#shipping-point').value && String(shipping.quote.point.cityCode) === shipping.city;
  }

  function shippingMessage(message, error = false) {
    const node = $('[data-shipping-status]');
    node.textContent = message; node.hidden = !message; node.dataset.shippingError = String(error);
  }

  function invalidateShipping() {
    shipping.generation += 1; shipping.quote = null; shipping.busy = false;
    clearTimeout(shipping.expiryTimer); shipping.expiryTimer = null;
    $('[data-calculate-shipping]').disabled = false; $('[data-calculate-shipping]').textContent = 'Рассчитать доставку';
    if (state.config?.delivery?.mode === 'cdek_receiver') $('[data-delivery-amount]').textContent = 'Не рассчитана';
    hide('[data-shipping-period]'); updateSubmit();
  }

  function resetPoints() {
    shipping.pointGeneration += 1; shipping.points = [];
    $('#shipping-point').value = ''; closePointPicker(); $('#shipping-point-search').value = '';
    shipping.filteredPoints = []; $('[data-point-list]').replaceChildren(); updatePointDisplay();
    $('[data-point-trigger]').disabled = true;
    $('#shipping-point').disabled = true; hide('[data-point-options]'); hide('[data-shipping-address]'); hide('[data-calculate-shipping]');
    invalidateShipping();
  }

  function updatePointDisplay() {
    const point = shipping.points.find(item => item.code === $('#shipping-point').value);
    $('[data-point-value]').textContent = point ? point.address : 'Выберите пункт выдачи';
    $('[data-point-detail]').textContent = point ? `СДЭК · ${point.city}` : 'Найдите удобный адрес';
  }

  function closePointPicker(restoreFocus = false) {
    hide('[data-point-panel]'); $('[data-point-trigger]').setAttribute('aria-expanded', 'false');
    $('#shipping-point-search').setAttribute('aria-expanded', 'false'); $('#shipping-point-search').removeAttribute('aria-activedescendant');
    shipping.activePoint = -1;
    if (restoreFocus) $('[data-point-trigger]').focus({ preventScroll: true });
  }

  function renderPointOptions() {
    const query = $('#shipping-point-search').value.trim().toLocaleLowerCase('ru');
    shipping.filteredPoints = shipping.points.filter(point => [point.address, point.name, point.code].some(value => typeof value === 'string' && value.toLocaleLowerCase('ru').includes(query)));
    shipping.activePoint = -1; $('#shipping-point-search').removeAttribute('aria-activedescendant');
    const selected = $('#shipping-point').value;
    const nodes = shipping.filteredPoints.map((point, index) => {
      const option = document.createElement('button'); option.type = 'button'; option.className = 'checkout-point-option'; option.tabIndex = -1;
      option.id = `shipping-point-option-${index}`; option.dataset.pointCode = point.code; option.dataset.active = 'false';
      option.setAttribute('role', 'option'); option.setAttribute('aria-selected', String(point.code === selected));
      const copy = document.createElement('span'); copy.className = 'checkout-point-option-copy';
      const address = document.createElement('span'); address.textContent = point.address;
      const detail = document.createElement('small'); detail.textContent = `${point.city} · ${typeof point.name === 'string' && point.name ? point.name : 'Пункт выдачи СДЭК'}`;
      const check = document.createElement('span'); check.className = 'checkout-point-check'; check.setAttribute('aria-hidden', 'true'); check.textContent = '✓';
      copy.append(address, detail); option.append(copy, check);
      option.addEventListener('click', () => selectPoint(point));
      return option;
    });
    $('[data-point-list]').replaceChildren(...nodes);
    $('[data-point-count]').textContent = `Пунктов: ${nodes.length}${query ? ` из ${shipping.points.length}` : ''}`;
    hide('[data-point-empty]', Boolean(nodes.length));
  }

  function openPointPicker() {
    if (!shipping.points.length || state.orderId || $('[data-point-trigger]').disabled) return;
    $('#shipping-point-search').value = ''; renderPointOptions(); hide('[data-point-panel]', false);
    $('[data-point-trigger]').setAttribute('aria-expanded', 'true'); $('#shipping-point-search').setAttribute('aria-expanded', 'true');
    $('#shipping-point-search').focus({ preventScroll: true });
    const selected = [...$('[data-point-list]').children].find(option => option.dataset.pointCode === $('#shipping-point').value);
    if (selected) $('[data-point-list]').scrollTop = selected.offsetTop - $('[data-point-list]').offsetTop;
    else $('[data-point-list]').scrollTop = 0;
  }

  function activePoint(index) {
    shipping.activePoint = index;
    for (const [optionIndex, option] of [...$('[data-point-list]').children].entries()) option.dataset.active = String(optionIndex === index);
    const option = $('[data-point-list]').children[index];
    if (option) { $('#shipping-point-search').setAttribute('aria-activedescendant', option.id); option.scrollIntoView({ block: 'nearest' }); }
  }

  function selectPoint(point) {
    if (!shipping.points.includes(point) || String(point.cityCode) !== shipping.city || state.orderId) return;
    $('#shipping-point').value = point.code; updatePointDisplay(); closePointPicker(true); calculateShipping();
  }

  function closeCitySuggestions(clear = false) {
    hide('[data-city-suggestions]'); $('#delivery-city').setAttribute('aria-expanded', 'false');
    $('#delivery-city').removeAttribute('aria-activedescendant'); shipping.activeCity = -1;
    for (const option of $('[data-city-suggestions]').children) option.setAttribute('aria-selected', 'false');
    if (clear) { shipping.cities = []; shipping.cityQuery = ''; $('[data-city-suggestions]').replaceChildren(); }
  }

  function openCitySuggestions() {
    if (!shipping.cities.length || shipping.city) return;
    hide('[data-city-suggestions]', false); $('#delivery-city').setAttribute('aria-expanded', 'true');
  }

  function cancelCitySearch() {
    shipping.searchGeneration += 1; clearTimeout(shipping.searchTimer); shipping.searchTimer = null;
    shipping.searchController?.abort(); shipping.searchController = null;
    $('#delivery-city').removeAttribute('aria-busy');
  }

  function activeCity(index) {
    openCitySuggestions(); shipping.activeCity = index;
    for (const [optionIndex, option] of [...$('[data-city-suggestions]').children].entries()) option.setAttribute('aria-selected', String(optionIndex === index));
    const option = $('[data-city-suggestions]').children[index];
    if (option) { $('#delivery-city').setAttribute('aria-activedescendant', option.id); option.scrollIntoView({ block: 'nearest' }); }
  }

  async function searchCities() {
    clearTimeout(shipping.searchTimer);
    if (shipping.config?.enabled !== true || state.orderId || shipping.city) return;
    const query = $('#delivery-city').value.trim();
    cancelCitySearch(); closeCitySuggestions(true);
    if (query.length < 2) { shippingMessage(query ? 'Введите хотя бы две буквы названия населённого пункта.' : ''); return; }
    const generation = shipping.searchGeneration;
    const controller = new AbortController(); shipping.searchController = controller;
    shippingMessage('Ищем населённый пункт…'); $('#delivery-city').setAttribute('aria-busy', 'true');
    try {
      const result = await api(`/api/shipping/cities?q=${encodeURIComponent(query)}`, { signal: controller.signal });
      if (generation !== shipping.searchGeneration || query !== $('#delivery-city').value.trim()) return;
      if (!Array.isArray(result.cities) || result.cities.some(city => !Number.isInteger(city.code) || city.code < 1 || typeof city.name !== 'string' || !city.name.trim())) throw new CheckoutError('Не удалось получить список населённых пунктов.');
      shipping.cities = result.cities; shipping.cityQuery = query;
      if (!shipping.cities.length) { shippingMessage('Населённый пункт не найден. Уточните название города, посёлка или станицы.'); return; }
      const options = shipping.cities.map((city, index) => {
        const option = document.createElement('button'); option.type = 'button'; option.className = 'checkout-city-option'; option.tabIndex = -1;
        option.id = `shipping-city-option-${city.code}-${index}`; option.dataset.cityCode = String(city.code); option.setAttribute('role', 'option'); option.setAttribute('aria-selected', 'false');
        const name = document.createElement('span'); name.textContent = city.name; option.append(name);
        if (typeof city.region === 'string' && city.region && city.region !== city.name) { const region = document.createElement('small'); region.textContent = city.region; option.append(region); }
        option.addEventListener('click', () => selectCity(city));
        return option;
      });
      $('[data-city-suggestions]').replaceChildren(...options);
      if ($('[data-city-field]').contains(document.activeElement)) openCitySuggestions();
      shippingMessage('Выберите населённый пункт из подсказок.');
    } catch (error) { if (generation === shipping.searchGeneration) shippingMessage(error.message, true); }
    finally { if (generation === shipping.searchGeneration) { shipping.searchController = null; $('#delivery-city').removeAttribute('aria-busy'); } }
  }

  async function selectCity(city) {
    if (!shipping.cities.includes(city) || shipping.config?.enabled !== true || state.orderId) return;
    cancelCitySearch(); resetPoints(); shipping.city = String(city.code);
    $('#delivery-city').value = city.name; $('#delivery-city').setCustomValidity(''); closeCitySuggestions(true); $('#delivery-city').focus();
    const generation = shipping.pointGeneration;
    shippingMessage('Получаем пункты выдачи СДЭК…');
    try {
      const result = await api(`/api/shipping/points?cityCode=${encodeURIComponent(shipping.city)}`);
      if (generation !== shipping.pointGeneration || state.orderId) return;
      if (!Array.isArray(result.points) || result.points.some(point => typeof point.code !== 'string' || typeof point.address !== 'string' || String(point.cityCode) !== shipping.city)) throw new CheckoutError('Не удалось получить пункты выдачи.');
      shipping.points = result.points;
      if (!shipping.points.length) { shippingMessage('В этом городе нет доступных пунктов выдачи. Выберите другой город.'); return; }
      $('#shipping-point').disabled = false; $('[data-point-trigger]').disabled = false; hide('[data-point-options]', false);
      shippingMessage('Выберите удобный пункт — рассчитаем доставку.');
    } catch (error) { if (generation === shipping.pointGeneration) shippingMessage(error.message, true); }
  }

  async function calculateShipping() {
    invalidateShipping();
    const point = shipping.points.find(item => item.code === $('#shipping-point').value);
    if (!point || !state.quote || shipping.config?.enabled !== true || state.orderId) return;
    const generation = shipping.generation;
    shipping.busy = true; updateSubmit();
    $('[data-calculate-shipping]').disabled = true; $('[data-calculate-shipping]').textContent = 'Рассчитываем…';
    $('[data-shipping-address]').textContent = `${point.city}, ${point.address}`; hide('[data-shipping-address]', false); hide('[data-calculate-shipping]', false);
    shippingMessage('Рассчитываем стоимость и срок доставки…');
    try {
      const result = await api('/api/shipping/quote', { method: 'POST', body: JSON.stringify({ items: state.items, pointCode: point.code }) });
      if (generation !== shipping.generation || state.orderId) return;
      if (typeof result.quoteId !== 'string' || !result.quoteId || !amountValid(result.amount) || result.currency !== 'RUB' || result.point?.code !== point.code || String(result.point.cityCode) !== shipping.city || typeof result.point.city !== 'string' || typeof result.point.address !== 'string' || !Number.isInteger(result.periodMin) || !Number.isInteger(result.periodMax) || result.periodMin < 0 || result.periodMax < result.periodMin || expiresAt(result.expiresAt) <= Date.now()) throw new CheckoutError('Не удалось подтвердить расчёт доставки. Повторите попытку.');
      shipping.quote = result;
      $('[data-delivery-amount]').textContent = money(result.amount);
      $('[data-shipping-period]').textContent = `Срок СДЭК: ${result.periodMin === result.periodMax ? result.periodMin : `${result.periodMin}–${result.periodMax}`} раб. дн., без дня приёмки. Доставка оплачивается при получении.`;
      hide('[data-shipping-period]', false);
      shippingMessage(`Доставка — ${money(result.amount)}. Оплачивается СДЭК отдельно при получении.`);
      shipping.expiryTimer = setTimeout(() => {
        if (shipping.quote?.quoteId !== result.quoteId) return;
        invalidateShipping(); shippingMessage('Расчёт устарел. Рассчитайте доставку ещё раз перед оплатой.');
      }, Math.min(2147483647, Math.max(0, expiresAt(result.expiresAt) - Date.now())));
    } catch (error) { if (generation === shipping.generation) shippingMessage(error.message, true); }
    finally {
      // A newer request owns its loading state.
      if (generation === shipping.generation) {
        shipping.busy = false; $('[data-calculate-shipping]').disabled = false; $('[data-calculate-shipping]').textContent = 'Рассчитать ещё раз'; updateSubmit();
      }
    }
  }

  function renderQuote(quote) {
    if (!Array.isArray(quote.items) || !quote.items.length || quote.currency !== 'RUB' || ![quote.subtotalAmount, quote.deliveryAmount, quote.totalAmount].every(amountValid) || quote.subtotalAmount + quote.deliveryAmount !== quote.totalAmount || quote.items.some(item => !item || !amountValid(item.unitAmount) || !amountValid(item.amount) || !Number.isSafeInteger(item.qty) || item.qty < 1 || item.unitAmount * item.qty !== item.amount || typeof item.title !== 'string') || quote.items.reduce((sum, item) => sum + item.amount, 0) !== quote.subtotalAmount) throw new CheckoutError('Не удалось подтвердить стоимость заказа. Попробуйте ещё раз.');
    const nodes = quote.items.map(item => {
      const row = document.createElement('li'); row.className = 'checkout-item';
      const picture = document.createElement('img'); picture.alt = ''; picture.width = 65; picture.height = 88;
      const source = imageUrl(item.image); if (source) picture.src = source;
      const copy = document.createElement('div'); copy.className = 'checkout-item__copy';
      const title = document.createElement('strong'); title.textContent = item.title;
      const detail = document.createElement('span'); detail.textContent = `Размер ${item.size} · ${item.qty} шт.`;
      const price = document.createElement('b'); price.textContent = money(item.amount);
      copy.append(title, detail, price); row.append(picture, copy); return row;
    });
    $('[data-items]').replaceChildren(...nodes);
    $('[data-subtotal]').textContent = money(quote.subtotalAmount);
    $('[data-delivery-label]').textContent = state.config?.delivery?.mode === 'cdek_receiver' ? 'Расчёт доставки СДЭК' : typeof state.config?.delivery?.label === 'string' ? state.config.delivery.label : 'Доставка СДЭК';
    $('[data-delivery-amount]').textContent = state.config?.delivery?.mode === 'cdek_receiver' ? (shippingValid() ? money(shipping.quote.amount) : 'Не рассчитана') : money(quote.deliveryAmount);
    $('[data-total]').textContent = money(quote.totalAmount);
    state.quote = quote;
    updateSubmit();
  }

  async function refreshQuote() {
    const generation = ++state.quoteGeneration;
    state.quote = null; invalidateShipping(); updateSubmit();
    const items = readCart(); state.items = items;
    hide('[data-empty]', Boolean(items.length)); hide('[data-checkout]', !items.length);
    if (!items.length) return;
    const quote = await api('/api/checkout/quote', { method: 'POST', body: JSON.stringify({ items }) });
    if (generation !== state.quoteGeneration || state.orderId) return;
    renderQuote(quote);
  }

  async function initialize() {
    clearError(); hide('[data-loading]', false);
    try {
      state.config = await api('/api/checkout/config');
      if (state.config.delivery?.mode === 'cdek_receiver') shipping.config = await api('/api/shipping/config');
      configure();
      await refreshQuote();
    } catch (error) { showError(error.message, initialize); }
    finally { hide('[data-loading]'); }
  }

  function uuid() {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const value = [...bytes].map(byte => byte.toString(16).padStart(2, '0')).join('');
    return `${value.slice(0, 8)}-${value.slice(8, 12)}-${value.slice(12, 16)}-${value.slice(16, 20)}-${value.slice(20)}`;
  }

  function storageAvailable() {
    try { sessionStorage.setItem('maharaCheckoutStorageCheck', '1'); sessionStorage.removeItem('maharaCheckoutStorageCheck'); return true; }
    catch { return false; }
  }

  function savedAttempt() {
    let saved;
    try { saved = JSON.parse(sessionStorage.getItem(ATTEMPT_KEY) || 'null'); }
    catch { throw new CheckoutError('Не удалось прочитать предыдущее оформление. Не создавайте повторный заказ: найдите его по почте или обратитесь в магазин.'); }
    if (saved === null) return null;
    // Old versions saved only a hash and cannot reconstruct an uncertain POST.
    // Do not silently replace that record with a new order identifier.
    if (saved?.version !== 1 || typeof saved.payload !== 'string' || saved.payload.length > 32768) throw new CheckoutError('Не удалось восстановить предыдущее оформление. Не создавайте повторный заказ: найдите его по почте или обратитесь в магазин.');
    let body;
    try { body = JSON.parse(saved.payload); } catch { throw new CheckoutError('Не удалось восстановить предыдущее оформление. Обратитесь в магазин перед повторным заказом.'); }
    if (!body || !/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(body.requestId) || !Array.isArray(body.items) || !body.items.length || !body.customer || !body.delivery || body.acceptedTerms !== true || !amountValid(body.expectedTotalAmount)) throw new CheckoutError('Не удалось восстановить предыдущее оформление. Обратитесь в магазин перед повторным заказом.');
    return saved;
  }

  function rememberAttempt(body) {
    const attempt = { version: 1, payload: JSON.stringify({ ...body, requestId: uuid() }) };
    try { sessionStorage.setItem(ATTEMPT_KEY, JSON.stringify(attempt)); }
    catch { throw new CheckoutError('Не удалось сохранить оформление в браузере. Заказ не отправлен. Разрешите хранение данных и попробуйте снова.'); }
    state.pending = attempt;
    return attempt;
  }

  function restoreAttemptFields(body) {
    for (const key of ['name', 'phone', 'email']) $(`#customer-${key}`).value = body.customer[key] || '';
    for (const key of ['city', 'point', 'address']) $(`#delivery-${key}`).value = body.delivery[key] || '';
    $('#accepted-terms').checked = body.acceptedTerms === true;
  }

  async function sendAttempt(attempt) {
    if (state.busy || !attempt) return;
    state.pending = attempt; state.busy = true; clearError(); updateSubmit();
    hide('[data-checkout]'); hide('[data-empty]');
    const body = JSON.parse(attempt.payload);
    try {
      // Reuse the exact saved bytes, including quoteId, even after its expiry,
      // a recalculation, cart edits or a document reload. The server first looks
      // up requestId and recovers an existing order before validating the quote.
      const result = await api('/api/checkout/orders', { method: 'POST', body: attempt.payload });
      if (!idValid(result.order?.id) || typeof result.order.status !== 'string' || !amountValid(result.order.totalAmount) || result.order.currency !== 'RUB' || typeof result.accessToken !== 'string' || result.accessToken.length < 16 || result.accessToken.length > 1024) throw new CheckoutError('Не удалось сохранить доступ к заказу.');
      const record = { token: result.accessToken, items: body.items.map(item => ({ ...item })), cleared: false };
      // Keep the pending POST until the capability is safely stored. A storage
      // failure must recover the same order rather than create another one.
      sessionStorage.setItem(ORDER_KEY + result.order.id, JSON.stringify(record));
      state.orderId = result.order.id; state.record = record;
      try { sessionStorage.removeItem(ATTEMPT_KEY); } catch { /* Replaying it remains idempotent. */ }
      state.pending = null;
      history.replaceState(null, '', `${location.pathname}?order=${encodeURIComponent(state.orderId)}`);
      await showOrder(result);
      $('[data-order-title]').setAttribute('tabindex', '-1'); $('[data-order-title]').focus({ preventScroll: true });
      $('[data-order]').scrollIntoView({ block: 'start', behavior: 'auto' });
      state.pollStarted = Date.now(); schedulePoll();
    } catch (error) {
      if (state.orderId) { showError(error.message, () => checkStatus(true)); return; }
      if (error.orderNotCreated === true) {
        try { sessionStorage.removeItem(ATTEMPT_KEY); } catch { /* No order exists for this rejected request. */ }
        state.pending = null; restoreAttemptFields(body);
        try {
          if (state.config) { await refreshQuote(); if (state.config.delivery?.mode === 'cdek_receiver') await calculateShipping(); }
          else await initialize();
          showError(`${error.message} Проверьте сумму, наличие и доставку перед повторным оформлением.`);
        } catch (quoteError) { showError(quoteError.message, initialize); }
      } else showError(`Не удалось подтвердить создание заказа. ${error.message} Повторите проверку: новое оформление пока недоступно, чтобы не создать второй заказ.`, () => sendAttempt(state.pending));
    } finally { state.busy = false; hide('[data-loading]'); updateSubmit(); }
  }

  async function startCheckout() {
    try {
      const pending = savedAttempt();
      if (pending) await sendAttempt(pending);
      else await initialize();
    } catch (error) { hide('[data-loading]'); hide('[data-checkout]'); showError(error.message); }
  }

  async function submit(event) {
    event.preventDefault();
    if (state.busy || state.pending || !state.available || !state.quote || !$('#accepted-terms').checked) return;
    const form = event.currentTarget;
    const normalizedPhone = $('#customer-phone').value.replace(/[\s()-]/g, '');
    $('#customer-phone').setCustomValidity(/^(?:\+[1-9][0-9]{9,14}|8[0-9]{10})$/.test(normalizedPhone) ? '' : 'Укажите телефон с кодом страны, например +7 999 123-45-67.');
    $('#customer-email').setCustomValidity($('#customer-email').value.trim().length <= 64 ? '' : 'Используйте адрес электронной почты длиной до 64 символов.');
    $('#customer-name').setCustomValidity($('#customer-name').value.trim() ? '' : 'Укажите имя получателя.');
    if (!form.reportValidity()) return;
    clearError();
    try {
      const items = readCart();
      if (JSON.stringify(items) !== JSON.stringify(state.items)) {
        await refreshQuote(); throw new CheckoutError('Корзина изменилась. Проверьте обновлённую сумму и повторите оформление.');
      }
      if (!storageAvailable()) throw new CheckoutError('Разрешите хранение данных в браузере, чтобы сохранить доступ к оплате заказа.');
      if (state.config.delivery?.mode === 'cdek_receiver' && !shippingValid()) throw new CheckoutError('Выберите пункт выдачи и рассчитайте доставку перед оплатой.');
      const values = new FormData(form);
      const delivery = state.config.delivery?.mode === 'cdek_receiver'
        ? { city: shipping.quote.point.city, point: shipping.quote.point.code, address: shipping.quote.point.address, quoteId: shipping.quote.quoteId }
        : { city: values.get('city').trim(), point: values.get('point').trim(), address: values.get('address').trim() };
      const body = { items, customer: { name: values.get('name').trim(), email: values.get('email').trim(), phone: values.get('phone').trim() }, delivery, acceptedTerms: true, expectedTotalAmount: state.quote.totalAmount };
      if (!crypto.subtle) throw new CheckoutError('Для оплаты откройте защищённую версию сайта через HTTPS.');
      await sendAttempt(rememberAttempt(body));
    } catch (error) {
      if (error.status === 409) {
        try { await refreshQuote(); } catch (quoteError) { showError(quoteError.message); return; }
        if (state.config?.delivery?.mode === 'cdek_receiver') await calculateShipping();
        showError(`${error.message} Проверьте обновлённую сумму и наличие перед повторной оплатой.`);
      } else showError(error.message);
    } finally { state.busy = false; updateSubmit(); }
  }

  function stopPoll() { clearTimeout(state.poll); state.poll = null; }

  function schedulePoll() {
    stopPoll();
    if (!state.order || FINAL_STATUSES.has(state.order.status) || !PENDING_STATUSES.has(state.order.status)) return;
    if (Date.now() - state.pollStarted >= POLL_WINDOW) {
      $('[data-order-hint]').textContent = 'Автоматическая проверка приостановлена. Нажмите «Проверить оплату», чтобы обновить статус.';
      return;
    }
    if (!document.hidden) state.poll = setTimeout(() => checkStatus(false), POLL_INTERVAL);
  }

  function clearQr() {
    hide('[data-qr-wrap]');
    $('[data-qr]').removeAttribute('src');
    if (state.qrSource) URL.revokeObjectURL(state.qrSource);
    state.qrSource = null; state.qrValue = null;
  }

  function renderPayment(payment) {
    const expired = expiresAt(payment?.expiresAt) > 0 && expiresAt(payment.expiresAt) <= Date.now();
    const url = expired ? null : bankUrl(payment?.url);
    const link = $('[data-payment-link]');
    link.hidden = !url;
    if (url) { link.href = url; link.rel = 'noreferrer'; } else link.removeAttribute('href');
    const svg = !expired && typeof payment?.qrSvg === 'string' && payment.qrSvg.length < 200000 && /<svg(?:\s|>)/i.test(payment.qrSvg) && !/<!DOCTYPE|<!ENTITY/i.test(payment.qrSvg) ? payment.qrSvg : null;
    if (svg && state.qrValue !== svg) {
      clearQr(); state.qrValue = svg;
      state.qrSource = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
      $('[data-qr]').src = state.qrSource; hide('[data-qr-wrap]', false);
    } else if (!svg) clearQr();
    const expires = expiresAt(payment?.expiresAt);
    if (expires > 0) {
      const date = new Date(expires);
      $('[data-expiry]').textContent = expired ? 'Срок ссылки на оплату завершился.' : `Оплата доступна до ${date.toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })}.`;
      hide('[data-expiry]', false);
    } else hide('[data-expiry]');
    return Boolean(url || svg);
  }

  async function settleCart() {
    if (!state.record || state.record.cleared || !Array.isArray(state.record.items)) return;
    const perform = () => {
      const marker = `maharaCartSettled:${state.orderId}`;
      try {
        if (localStorage.getItem(marker)) { state.record.cleared = true; }
        else {
          const purchased = new Map(state.record.items.map(item => [`${item.id}:${item.size}`, item.qty]));
          const remaining = readCart().map(item => ({ ...item, qty: Math.max(0, item.qty - (purchased.get(`${item.id}:${item.size}`) || 0)) })).filter(item => item.qty > 0);
          localStorage.setItem(CART_KEY, JSON.stringify(remaining));
          state.record.cleared = true;
          try { localStorage.setItem(marker, '1'); } catch { /* Keep the in-memory and session marker when local storage is full. */ }
        }
        sessionStorage.setItem(ORDER_KEY + state.orderId, JSON.stringify(state.record));
      } catch { /* Payment remains authoritative even if cart storage is unavailable. */ }
    };
    if (navigator.locks?.request) await navigator.locks.request('mahara-cart-settlement', perform);
    else perform();
  }

  async function showOrder(data) {
    const order = data.order;
    if (!order || order.id !== state.orderId || typeof order.status !== 'string' || !amountValid(order.totalAmount) || order.currency !== 'RUB') throw new CheckoutError('Не удалось подтвердить данные заказа. Проверьте статус ещё раз.');
    state.order = order;
    hide('[data-order]', false);
    $('[data-order-number]').textContent = `Заказ № ${String(order.number || order.id)}`;
    $('[data-order-amount]').textContent = money(order.totalAmount);
    const success = order.status === 'paid';
    const final = FINAL_STATUSES.has(order.status);
    const titles = { paid: 'Оплата получена', canceled: 'Оплата отменена', cancelled: 'Оплата отменена', expired: 'Время оплаты истекло', failed: 'Оплата не завершена', refunded: 'Возврат оформлен', partial_refunded: 'Частичный возврат оформлен', refund_pending: 'Возврат обрабатывается' };
    $('[data-order-title]').textContent = titles[order.status] || 'Заказ создан';
    $('[data-order-symbol]').textContent = success ? '✓' : final ? '—' : '↗';
    const statuses = { paid: order.fulfillmentStatus === 'completed' ? 'Спасибо за покупку. Заказ выполнен.' : order.fulfillmentStatus === 'shipped' ? 'Ваш заказ отправлен через СДЭК.' : 'Спасибо за заказ. Оплата подтверждена.', canceled: 'Заказ не оплачен. Вы можете оформить его заново.', cancelled: 'Заказ не оплачен. Вы можете оформить его заново.', expired: 'Срок оплаты заказа завершился. Вы можете оформить новый заказ.', failed: 'Не удалось завершить оплату. Проверьте заказ перед повторным оформлением.', refunded: 'Возврат подтверждён. Срок зачисления зависит от вашего банка.', partial_refunded: 'Возврат части оплаты подтверждён. Срок зачисления зависит от вашего банка.', refund_pending: 'Запрос на возврат передан в банк. Ожидаем подтверждение.' };
    const hasPayment = !final && order.status !== 'refund_pending' && renderPayment(data.payment);
    const expiredPending = !final && order.status !== 'refund_pending' && !hasPayment && expiresAt(data.payment?.expiresAt) > 0 && expiresAt(data.payment.expiresAt) <= Date.now();
    if (final || order.status === 'refund_pending') { clearQr(); hide('[data-payment-link]'); $('[data-payment-link]').removeAttribute('href'); hide('[data-expiry]'); if (final) stopPoll(); }
    $('[data-order-status]').textContent = statuses[order.status] || (expiredPending ? 'Время оплаты истекло. Проверяем итоговый статус в банке.' : hasPayment ? 'Заказ ожидает оплаты через СБП.' : 'Готовим ссылку для оплаты. Статус обновится автоматически.');
    hide('[data-check-status]', final);
    $('[data-order-hint]').textContent = success ? (typeof order.trackingNumber === 'string' && order.trackingNumber ? `Номер отправления СДЭК: ${order.trackingNumber}` : 'Ваш заказ сохранён.') : final ? 'Возвращайтесь в каталог, когда будете готовы.' : order.status === 'refund_pending' ? 'Статус возврата обновится автоматически.' : expiredPending ? 'Дождитесь подтверждения статуса перед повторной оплатой заказа.' : 'Подтверждение оплаты появится здесь автоматически.';
    if (success) await settleCart();
  }

  async function checkStatus(manual) {
    if (state.checking || !state.orderId || !state.record?.token) return;
    if (manual) { state.pollStarted = Date.now(); clearError(); }
    state.checking = true;
    const button = $('[data-check-status]'); button.disabled = true; button.textContent = 'Проверяем…';
    try {
      const result = await api(`/api/checkout/orders/${encodeURIComponent(state.orderId)}`, { headers: { 'X-Order-Token': state.record.token } });
      await showOrder(result); clearError();
    } catch (error) {
      showError(error.message);
      if ([401, 403, 404].includes(error.status)) { stopPoll(); clearQr(); hide('[data-payment-link]'); state.order = null; }
    } finally { state.checking = false; button.disabled = false; button.textContent = 'Проверить оплату'; schedulePoll(); }
  }

  async function restoreOrder(id) {
    hide('[data-loading]', false);
    try {
      if (!idValid(id)) throw new CheckoutError('Проверьте ссылку на заказ.');
      state.orderId = id;
      try { state.record = JSON.parse(sessionStorage.getItem(ORDER_KEY + id) || 'null'); } catch { state.record = null; }
      if (!state.record || typeof state.record.token !== 'string' || state.record.token.length < 16) throw new CheckoutError('Откройте заказ в той вкладке, где оформляли его, или запросите новую ссылку через «Найти оформленный заказ». Если восстановление по почте недоступно, обратитесь в магазин.');
      state.pollStarted = Date.now();
      await checkStatus(false);
    } catch (error) { showError(error.message); }
    finally { hide('[data-loading]'); }
  }

  async function configureRecovery() {
    try { const config = await api('/api/checkout/recovery/config'); $('[data-recovery]').hidden = config.enabled !== true; }
    catch { /* A mail outage must not block checkout or saved order access. */ }
  }

  async function recoverAccess(token) {
    // The fragment does not enter HTTP/referrer logs. Remove it immediately.
    history.replaceState(null, '', location.pathname);
    try {
      if (!/^[a-zA-Z0-9_-]{43}$/.test(token)) throw new CheckoutError('Ссылка на заказ недействительна. Запросите новую.');
      if (!storageAvailable()) throw new CheckoutError('Разрешите хранение данных в браузере, чтобы открыть заказ.');
      const data = await api('/api/checkout/recovery/exchange', { method: 'POST', body: JSON.stringify({ token }) });
      if (!idValid(data.order?.id) || typeof data.accessToken !== 'string' || data.accessToken.length < 16 || data.accessToken.length > 1024) throw new CheckoutError('Не удалось открыть заказ. Запросите новую ссылку.');
      state.orderId = data.order.id;
      // Recovery on another device must never subtract an unrelated local cart.
      state.record = { token: data.accessToken, items: [], cleared: true };
      sessionStorage.setItem(ORDER_KEY + state.orderId, JSON.stringify(state.record));
      history.replaceState(null, '', `${location.pathname}?order=${encodeURIComponent(state.orderId)}`);
      await showOrder(data); state.pollStarted = Date.now(); schedulePoll();
    } catch (error) { showError(error.message); }
    finally { hide('[data-loading]'); }
  }

  $('[data-recovery-form]').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget; if (!form.reportValidity()) return;
    const button = form.querySelector('button'); if (button.disabled) return;
    button.disabled = true; $('[data-recovery-message]').textContent = 'Отправляем запрос…';
    try {
      const data = await api('/api/checkout/recovery/request', { method: 'POST', body: JSON.stringify({ number: $('#recovery-number').value.trim(), email: $('#recovery-email').value.trim() }) });
      $('[data-recovery-message]').textContent = data.message || 'Если данные совпали с заказом, мы отправим ссылку на почту.';
    } catch (error) { $('[data-recovery-message]').textContent = error.message; }
    finally { button.disabled = false; }
  });

  $('[data-checkout-form]').addEventListener('submit', submit);
  $('#accepted-terms').addEventListener('change', updateSubmit);
  for (const selector of ['#customer-name', '#customer-phone', '#customer-email']) $(selector).addEventListener('input', () => $(selector).setCustomValidity(''));
  $('#delivery-city').addEventListener('input', () => {
    if (state.config?.delivery?.mode !== 'cdek_receiver') return;
    cancelCitySearch(); shipping.city = null; resetPoints(); closeCitySuggestions(true);
    const query = $('#delivery-city').value.trim();
    $('#delivery-city').setCustomValidity(query ? 'Выберите населённый пункт из подсказок.' : '');
    if (shipping.config?.enabled !== true) return;
    shippingMessage(query.length === 1 ? 'Введите хотя бы две буквы названия населённого пункта.' : '');
    if (query.length >= 2) shipping.searchTimer = setTimeout(searchCities, 350);
  });
  $('#delivery-city').addEventListener('focus', () => {
    if (state.config?.delivery?.mode !== 'cdek_receiver' || shipping.city || shipping.config?.enabled !== true) return;
    if (shipping.cities.length && shipping.cityQuery === $('#delivery-city').value.trim()) openCitySuggestions();
    else if ($('#delivery-city').value.trim().length >= 2 && !shipping.searchController) { clearTimeout(shipping.searchTimer); shipping.searchTimer = setTimeout(searchCities, 350); }
  });
  $('#delivery-city').addEventListener('keydown', event => {
    if (state.config?.delivery?.mode !== 'cdek_receiver') return;
    if (event.key === 'Escape') { cancelCitySearch(); closeCitySuggestions(); return; }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (shipping.cities.length) activeCity((shipping.activeCity + (event.key === 'ArrowDown' ? 1 : shipping.activeCity < 0 ? 0 : -1) + shipping.cities.length) % shipping.cities.length);
      else if (!shipping.city && shipping.config?.enabled === true && $('#delivery-city').value.trim().length >= 2) searchCities();
    } else if (event.key === 'Enter' && !shipping.city) {
      event.preventDefault();
      if (!$('[data-city-suggestions]').hidden && shipping.activeCity >= 0) selectCity(shipping.cities[shipping.activeCity]);
      else if (!$('[data-city-suggestions]').hidden && shipping.cities.length === 1) selectCity(shipping.cities[0]);
      else if (shipping.config?.enabled === true) searchCities();
    }
  });
  $('[data-city-field]').addEventListener('focusout', event => { if (!event.currentTarget.contains(event.relatedTarget)) closeCitySuggestions(); });
  $('[data-point-trigger]').addEventListener('click', () => {
    if ($('[data-point-panel]').hidden) openPointPicker(); else closePointPicker(true);
  });
  $('[data-point-trigger]').addEventListener('keydown', event => {
    if (['ArrowDown', 'ArrowUp'].includes(event.key)) { event.preventDefault(); openPointPicker(); if (shipping.filteredPoints.length) activePoint(event.key === 'ArrowDown' ? 0 : shipping.filteredPoints.length - 1); }
  });
  $('#shipping-point-search').addEventListener('input', renderPointOptions);
  $('#shipping-point-search').addEventListener('keydown', event => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (shipping.filteredPoints.length) activePoint((shipping.activePoint + (event.key === 'ArrowDown' ? 1 : shipping.activePoint < 0 ? 0 : -1) + shipping.filteredPoints.length) % shipping.filteredPoints.length);
    } else if (event.key === 'Home' || event.key === 'End') {
      if (shipping.activePoint >= 0 && shipping.filteredPoints.length) { event.preventDefault(); activePoint(event.key === 'Home' ? 0 : shipping.filteredPoints.length - 1); }
    } else if (event.key === 'Enter') {
      event.preventDefault();
      const point = shipping.filteredPoints[shipping.activePoint] || (shipping.filteredPoints.length === 1 ? shipping.filteredPoints[0] : null);
      if (point) selectPoint(point);
    }
  });
  $('[data-point-options]').addEventListener('keydown', event => {
    if (event.key === 'Escape' && !$('[data-point-panel]').hidden) { event.preventDefault(); closePointPicker(true); }
  });
  $('[data-point-options]').addEventListener('focusout', event => { if (!event.currentTarget.contains(event.relatedTarget)) closePointPicker(); });
  document.addEventListener('pointerdown', event => { if (!$('[data-point-options]').contains(event.target)) closePointPicker(); });
  $('#shipping-point').addEventListener('change', () => { updatePointDisplay(); calculateShipping(); });
  $('[data-calculate-shipping]').addEventListener('click', calculateShipping);
  $('[data-retry]').addEventListener('click', () => state.retry?.());
  $('[data-check-status]').addEventListener('click', () => checkStatus(true));
  $('[data-qr]').addEventListener('error', () => {
    clearQr(); $('[data-order-hint]').textContent = 'QR-код не загрузился. Используйте ссылку на оплату или проверьте статус ещё раз.';
  });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopPoll();
    else if (state.order && PENDING_STATUSES.has(state.order.status)) {
      if (Date.now() - state.pollStarted < POLL_WINDOW) checkStatus(false);
      else schedulePoll();
    }
  });
  window.addEventListener('storage', event => {
    if (event.key === CART_KEY && !state.orderId && !state.pending && !state.busy && state.config) refreshQuote().catch(error => showError(error.message));
  });
  window.addEventListener('pagehide', stopPoll);
  window.addEventListener('pageshow', event => { if (event.persisted && state.orderId) checkStatus(true); });
  const orderId = new URLSearchParams(location.search).get('order');
  const access = new URLSearchParams(location.hash.slice(1)).get('access');
  configureRecovery();
  if (access !== null) recoverAccess(access);
  else if (orderId !== null) restoreOrder(orderId);
  else startCheckout();
})();
