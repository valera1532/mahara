(() => {
  'use strict';
  document.documentElement.classList.add('js');
  const $ = selector => document.querySelector(selector);
  const money = new Intl.NumberFormat('ru-RU');
  const statusNames = { in_stock: 'В наличии', out: 'Закончился', untracked: 'Остатки не заданы', archived: 'Архив' };
  const typeNames = { receipt: 'Приход', writeoff: 'Списание', set: 'Инвентаризация' };
  const tones = { in_stock: 'good', out: 'danger', untracked: 'muted', archived: 'muted' };
  let snapshot;
  let session;
  let refreshing = false;
  let movementSelection;
  let movementBusy = false;
  let deleteBusy = false;
  let deleteContent;
  let deleteProductId;
  let uncertainMovement;
  let toastTimer;

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function announce(message) {
    $('[data-toast]').textContent = message;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { $('[data-toast]').textContent = ''; }, 6000);
  }
  async function request(url, options = {}) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal, ...options });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        const error = new Error(data.error || 'Не удалось выполнить запрос. Попробуйте ещё раз.');
        error.status = response.status;
        if (response.status === 401) window.location.replace('/admin/login');
        throw error;
      }
      return data;
    } catch (error) {
      if (!error.status) error.message = 'Нет ответа от сервера. Проверьте подключение и повторите запрос.';
      throw error;
    } finally { clearTimeout(timeout); }
  }
  function write(url, method, body) {
    return request(url, { method, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.csrfToken }, body: JSON.stringify(body) });
  }
  function selectedProducts() {
    const query = $('[data-search]').value.trim().toLocaleLowerCase('ru-RU');
    const category = $('[data-category]').value;
    const status = $('[data-status]').value;
    return snapshot.products.filter(product => {
      if (status === 'archived' ? !product.archived : product.archived) return false;
      if (status !== 'all' && status !== 'archived' && product.status !== status) return false;
      if (category !== 'all' && product.category !== category) return false;
      return !query || `${product.title} ${product.id} ${product.subtitle}`.toLocaleLowerCase('ru-RU').includes(query);
    });
  }
  function actionButton(label, action, product, variant) {
    const button = element('button', '', label);
    button.type = 'button';
    button.dataset.action = action;
    button.dataset.product = product.id;
    button.dataset.size = variant.size;
    button.setAttribute('aria-label', `${label}: ${product.title}, ${variant.size}`);
    return button;
  }
  function productCard(product) {
    const card = element('article', 'inventory-product');
    card.dataset.productId = product.id;
    const header = element('div', 'inventory-product__header');
    const image = element('img', 'inventory-product__image');
    image.src = `/${product.image}`;
    image.alt = '';
    image.width = 56;
    image.height = 70;
    image.loading = 'lazy';
    const info = element('div', 'inventory-product__info');
    const title = element('h3', '', product.title);
    info.append(title, element('p', 'inventory-product__meta', `${product.category === 'hoodie' ? 'Худи' : 'Футболка'} · ${product.id} · ${money.format(product.price)} ₽`));
    const unknown = product.variants.filter(variant => !variant.archived && !variant.initialized).length;
    info.append(element('p', 'inventory-product__stock', `Учтено: ${money.format(product.total)} шт.${unknown ? ` · Не заданы ${unknown} разм.` : ''}`));
    const badge = element('span', 'inventory-badge', statusNames[product.archived ? 'archived' : product.status]);
    badge.dataset.tone = tones[product.archived ? 'archived' : product.status];
    header.append(image, info, badge);
    if (!product.archived) {
      const edit = element('a', 'inventory-product__edit', 'Товар ↗');
      edit.href = `/admin.html?product=${encodeURIComponent(product.id)}`;
      edit.setAttribute('aria-label', `Редактировать ${product.title}`);
      header.append(edit);
      const remove = element('button', 'inventory-product__remove', 'Убрать с витрины');
      remove.type = 'button'; remove.dataset.removeProduct = product.id;
      remove.setAttribute('aria-label', `Убрать с витрины ${product.title}`);
      header.append(remove);
    }
    card.append(header);
    const wrapper = element('div', 'inventory-table-wrap');
    const table = element('table', 'inventory-table');
    const caption = element('caption', 'sr-only', `Остатки ${product.title}`);
    const thead = element('thead');
    const headings = element('tr');
    ['Размер', 'На складе', 'Операции'].forEach(text => {
      const th = element('th', '', text); th.scope = 'col'; headings.append(th);
    });
    thead.append(headings);
    const tbody = element('tbody');
    product.variants.forEach(variant => {
      const row = element('tr');
      row.dataset.size = variant.size;
      const archived = product.archived || variant.archived;
      const size = element('th', '', variant.size); size.scope = 'row';
      if (variant.archived && !product.archived) size.append(element('small', 'inventory-archived-size', 'Убран из продажи'));
      const stock = element('td');
      const quantity = element('strong', 'inventory-quantity', variant.initialized ? `${money.format(variant.quantity)} шт.` : 'Не задано');
      quantity.dataset.tone = !variant.initialized || archived ? 'muted' : variant.quantity === 0 ? 'danger' : 'good';
      stock.append(quantity);
      const operations = element('td');
      const actions = element('div', 'inventory-actions');
      if (!archived) {
        actions.append(actionButton('Приход', 'receipt', product, variant));
        const writeoff = actionButton('Списание', 'writeoff', product, variant);
        writeoff.disabled = !variant.initialized || variant.quantity === 0;
        actions.append(writeoff, actionButton('Пересчитать', 'set', product, variant));
      } else actions.append(element('span', 'inventory-archive-note', 'История сохранена'));
      operations.append(actions);
      row.append(size, stock, operations);
      tbody.append(row);
    });
    table.append(caption, thead, tbody);
    wrapper.append(table);
    card.append(wrapper);
    return card;
  }
  function renderProducts() {
    const products = selectedProducts();
    $('[data-product-list]').replaceChildren(...products.map(productCard));
    $('[data-empty]').hidden = products.length !== 0;
    $('[data-export]').disabled = products.length === 0;
    document.querySelectorAll('[data-status-filter]').forEach(button => {
      button.setAttribute('aria-pressed', String(button.dataset.statusFilter === $('[data-status]').value));
    });
  }
  function render(data) {
    snapshot = data;
    document.querySelectorAll('[data-stat]').forEach(node => { node.textContent = money.format(data.summary[node.dataset.stat] || 0); });
    $('[data-inventory-content]').hidden = false;
    $('[data-inventory-loading]').hidden = true;
    $('[data-inventory-error]').hidden = true;
    $('[data-reload]').hidden = true;
    renderProducts();
  }
  async function refresh() {
    if (refreshing) return;
    refreshing = true;
    $('[data-refresh]').disabled = true;
    $('[data-refresh]').textContent = 'Обновляем…';
    try {
      if (!session) session = await request('/api/admin/session');
      render(await request('/api/admin/inventory'));
    }
    catch (error) {
      $('[data-inventory-loading]').hidden = true;
      $('[data-inventory-error]').hidden = false;
      $('[data-reload]').hidden = false;
      const copy = $('[data-inventory-error] [data-error-copy]') || $('[data-inventory-error] p');
      if (copy) copy.textContent = error.message + (snapshot ? ' Показаны последние загруженные данные.' : '');
      else $('[data-inventory-error]').textContent = error.message + (snapshot ? ' Показаны последние загруженные данные.' : '');
    } finally {
      refreshing = false;
      $('[data-refresh]').disabled = false;
      $('[data-refresh]').textContent = 'Обновить';
    }
  }
  function lookup(productId, size) {
    const product = snapshot.products.find(product => product.id === productId);
    const variant = product?.variants.find(variant => variant.size === size);
    return { product, variant };
  }
  function updateMovementFields() {
    const form = $('[data-movement-form]');
    const type = form.elements.type.value;
    const quantity = form.elements.quantity;
    quantity.min = type === 'set' ? '0' : '1';
    form.elements.note.required = type !== 'receipt';
    $('[data-movement-title]').textContent = typeNames[type];
    const current = movementSelection.variant;
    const parsed = Number(quantity.value);
    const after = type === 'set' ? parsed : current.quantity + (type === 'writeoff' ? -parsed : parsed);
    $('[data-movement-current]').textContent = `Сейчас: ${current.initialized ? `${current.quantity} шт.` : 'не задано'}.${quantity.value !== '' && Number.isSafeInteger(after) && after >= 0 ? ` После операции: ${after} шт.` : ''}`;
  }
  function openMovement(product, variant, type) {
    if (uncertainMovement) {
      announce('Сначала проверьте результат предыдущей операции: повторите её в открытой форме. Повтор не изменит остаток дважды.');
      $('[data-movement-dialog]').showModal();
      return;
    }
    movementSelection = { product, variant, requestId: crypto.randomUUID() };
    const form = $('[data-movement-form]');
    form.reset();
    form.querySelectorAll('input, select, textarea').forEach(node => { node.disabled = false; });
    form.elements.type.value = type;
    form.elements.quantity.value = type === 'set' ? variant.quantity : '';
    form.elements.type.querySelector('[value="writeoff"]').disabled = !variant.initialized || variant.quantity === 0;
    $('[data-movement-product]').textContent = `${product.title} · ${variant.size}`;
    $('[data-movement-error]').textContent = '';
    $('[data-movement-submit]').textContent = 'Сохранить';
    $('[data-movement-submit]').disabled = false;
    updateMovementFields();
    $('[data-movement-dialog]').showModal();
    form.elements.quantity.focus();
  }
  $('[data-product-list]').addEventListener('click', event => {
    const remove = event.target.closest('[data-remove-product]');
    if (remove) { openDelete(remove.dataset.removeProduct); return; }
    const button = event.target.closest('[data-action]');
    if (!button) return;
    const { product, variant } = lookup(button.dataset.product, button.dataset.size);
    if (!variant || product.archived || variant.archived) return;
    openMovement(product, variant, button.dataset.action);
  });
  async function openDelete(productId) {
    deleteContent = null;
    deleteProductId = productId;
    $('[data-delete-product]').textContent = 'Проверяем актуальную версию товара…';
    $('[data-delete-error]').textContent = '';
    $('[data-delete-submit]').disabled = true;
    $('[data-delete-dialog]').showModal();
    try {
      const content = await request('/api/content');
      if (deleteProductId !== productId || !$('[data-delete-dialog]').open) return;
      const product = content.products.find(product => product.id === productId);
      if (!product) throw new Error('Этот товар уже убран с витрины. Обновите склад.');
      deleteContent = content;
      $('[data-delete-product]').textContent = `${product.title} · ${product.id}`;
      $('[data-delete-submit]').disabled = false;
    } catch (error) { $('[data-delete-error]').textContent = error.message; }
  }
  $('[data-delete-form]').addEventListener('submit', async event => {
    event.preventDefault();
    if (deleteBusy || !deleteContent) return;
    deleteBusy = true;
    $('[data-delete-submit]').disabled = true;
    $('[data-delete-error]').textContent = '';
    const content = { ...deleteContent, products: deleteContent.products.filter(product => product.id !== deleteProductId) };
    try {
      await write('/api/admin/content', 'PUT', content);
      $('[data-delete-dialog]').close();
      await refresh();
      announce('Товар убран с витрины. Учтённые остатки и история сохранены в архиве.');
    } catch (error) {
      $('[data-delete-error]').textContent = error.message + (error.status === 409 ? ' Закройте окно и откройте удаление заново.' : '');
      $('[data-delete-submit]').disabled = error.status === 409;
    } finally { deleteBusy = false; }
  });
  $('[data-movement-form]').addEventListener('input', () => { if (!movementBusy && !uncertainMovement) updateMovementFields(); });
  $('[data-movement-form]').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    if (movementBusy || !form.reportValidity()) return;
    const selection = movementSelection;
    const payload = uncertainMovement || {
      requestId: selection.requestId, productId: selection.product.id, size: selection.variant.size,
      type: form.elements.type.value, quantity: Number(form.elements.quantity.value), expectedRevision: selection.variant.revision, note: form.elements.note.value.trim()
    };
    if (payload.type === 'writeoff' && payload.quantity > selection.variant.quantity) {
      $('[data-movement-error]').textContent = 'Нельзя списать больше, чем есть на складе.'; return;
    }
    if (payload.type !== 'receipt' && !payload.note) {
      $('[data-movement-error]').textContent = 'Укажите причину списания или пересчёта.'; return;
    }
    movementBusy = true;
    $('[data-movement-submit]').disabled = true;
    $('[data-movement-submit]').textContent = 'Сохраняем…';
    $('[data-movement-error]').textContent = '';
    form.querySelectorAll('input, select, textarea').forEach(node => { node.disabled = true; });
    try {
      render(await write('/api/admin/inventory/movements', 'POST', payload));
      uncertainMovement = null;
      $('[data-movement-dialog]').close();
      announce('Операция сохранена. Остатки обновлены.');
    } catch (error) {
      $('[data-movement-error]').textContent = error.message;
      if (!error.status || error.status >= 500) {
        uncertainMovement = payload;
        $('[data-movement-error]').textContent += ' Нажмите «Проверить и повторить»: одна операция будет учтена только один раз.';
      } else {
        uncertainMovement = null;
        movementSelection.requestId = crypto.randomUUID();
        if (error.status === 409) {
          $('[data-movement-error]').textContent += ' Закройте форму и откройте операцию заново с обновлённым остатком.';
          await refresh();
        } else form.querySelectorAll('input, select, textarea').forEach(node => { node.disabled = false; });
      }
      $('[data-movement-submit]').disabled = error.status === 409;
    } finally {
      movementBusy = false;
      $('[data-movement-submit]').textContent = uncertainMovement ? 'Проверить и повторить' : 'Сохранить';
    }
  });
  document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => {
    if (!movementBusy && !deleteBusy) button.closest('dialog').close();
  }));
  document.querySelectorAll('dialog').forEach(dialog => dialog.addEventListener('cancel', event => {
    if (movementBusy || deleteBusy) event.preventDefault();
  }));
  ['[data-search]', '[data-category]', '[data-status]'].forEach(selector => $(selector).addEventListener(selector === '[data-search]' ? 'input' : 'change', () => { if (snapshot) renderProducts(); }));
  document.querySelectorAll('[data-status-filter]').forEach(button => button.addEventListener('click', () => {
    $('[data-status]').value = button.dataset.statusFilter;
    if (snapshot) renderProducts();
  }));
  $('[data-clear-filters]').addEventListener('click', () => {
    $('[data-search]').value = '';
    $('[data-category]').value = 'all';
    $('[data-status]').value = 'all';
    renderProducts();
  });
  $('[data-refresh]').addEventListener('click', refresh);
  $('[data-reload]').addEventListener('click', refresh);
  $('[data-export]').addEventListener('click', () => {
    const rows = [['Товар', 'Код', 'Категория', 'Размер', 'Остаток', 'Статус']];
    selectedProducts().forEach(product => product.variants.forEach(variant => rows.push([
      product.title, product.id, product.category === 'hoodie' ? 'Худи' : 'Футболка', variant.size,
      variant.initialized ? variant.quantity : '',
      product.archived || variant.archived ? 'Архив' : !variant.initialized ? 'Не задано' : variant.quantity === 0 ? 'Нет в наличии' : 'В наличии'
    ])));
    const csv = rows.map(row => row.map(value => {
      let text = String(value).replace(/[\u0000-\u001f]/g, ' ');
      if (/^\s*[=+@-]/.test(text)) text = `'${text}`;
      return `"${text.replaceAll('"', '""')}"`;
    }).join(';')).join('\r\n');
    const url = URL.createObjectURL(new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8' }));
    const link = element('a'); link.href = url;
    link.download = `mahara-stock-${new Date().toLocaleDateString('sv-SE')}.csv`;
    document.body.append(link); link.click(); link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    announce('Выгружены остатки товаров с учётом выбранных фильтров.');
  });
  $('[data-logout]').addEventListener('click', async event => {
    if (movementBusy || deleteBusy) return;
    const button = event.currentTarget; button.disabled = true;
    try { await write('/api/admin/logout', 'POST', {}); window.location.replace('/admin/login'); }
    catch (error) { announce(error.message); button.disabled = false; }
  });
  (async () => {
    try { session = await request('/api/admin/session'); await refresh(); }
    catch (error) {
      $('[data-inventory-loading]').hidden = true;
      $('[data-inventory-error]').hidden = false;
      $('[data-reload]').hidden = false;
      const copy = $('[data-inventory-error] [data-error-copy]') || $('[data-inventory-error] p');
      if (copy) copy.textContent = error.message;
      else $('[data-inventory-error]').textContent = error.message;
    }
  })();
})();
