(async () => {
  'use strict';

  const workspace = document.querySelector('[data-cms-workspace]');
  if (!workspace) return;
  document.documentElement.classList.add('js');
  const $ = selector => document.querySelector(selector);
  const $$ = selector => Array.from(document.querySelectorAll(selector));
  const escapeHTML = value => String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));
  const clone = value => JSON.parse(JSON.stringify(value));
  const api = window.MaharaCMS;
  const form = $('#cms-form');
  const fieldsNode = $('[data-cms-fields]');
  const previewNode = $('[data-cms-preview]');
  const saveButton = $('[data-cms-save]');
  const resetButton = $('[data-cms-reset]');
  const statusNode = $('[data-cms-status]');
  const errorNode = $('[data-cms-error]');
  const resetPanel = $('#cms-reset-confirm');
  const allowedSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
  const productFields = [
    { key: 'title', label: 'Название товара', type: 'text' },
    { key: 'subtitle', label: 'Короткое описание', type: 'text' },
    { key: 'price', label: 'Цена, ₽', type: 'number', half: true },
    { key: 'label', label: 'Метка на карточке', type: 'text', half: true },
    { key: 'description', label: 'Описание товара', type: 'textarea' },
    { key: 'sizes', label: 'Доступные размеры', type: 'sizes' },
    { key: 'image', label: 'Основная фотография', type: 'image' },
    { key: 'altImage', label: 'Второй ракурс', type: 'image' }
  ];
  let draft;
  let baseline;
  let defaults;
  let selection = { type: 'section', id: 'hero' };
  let ready = false;
  let busy = false;
  let dirty = false;
  let uploads = 0;
  let savedMessage = 'Все изменения сохранены';
  let previewPhoto = 'image';
  const uploadErrors = new Map();

  if (!api) {
    statusNode.dataset.state = 'error';
    statusNode.textContent = 'Не удалось открыть управление сайтом';
    errorNode.textContent = 'Обновите страницу и попробуйте снова.';
    workspace.setAttribute('aria-busy', 'false');
    return;
  }

  function selectedContent(which = draft, target = selection) {
    return target.type === 'section' ? which.sections[target.id] : which.products.find(product => product.id === target.id);
  }

  function selectedFields(target = selection) {
    return target.type === 'section' ? api.sectionDefinitions.find(section => section.id === target.id).fields : productFields;
  }

  const fieldPath = (key, target = selection) => `${target.type}:${target.id}:${key}`;
  const sameValue = (a, b) => Array.isArray(a) ? JSON.stringify(a) === JSON.stringify(b) : a === b;

  function countChanges() {
    let count = 0;
    for (const definition of api.sectionDefinitions) {
      for (const field of definition.fields) if (!sameValue(draft.sections[definition.id][field.key], baseline.sections[definition.id][field.key])) count += 1;
    }
    for (const product of draft.products) {
      const original = baseline.products.find(item => item.id === product.id);
      for (const field of productFields) if (!sameValue(product[field.key], original[field.key])) count += 1;
    }
    return count;
  }

  function validateField(field, value) {
    if (field.key === 'title' && !String(value ?? '').trim()) return 'Введите заголовок.';
    if (field.type === 'number' && (value === '' || !Number.isFinite(Number(value)) || !Number.isInteger(Number(value)) || Number(value) < 0 || Number(value) > 10000000)) return 'Введите целую цену от 0 до 10 000 000 ₽.';
    if (field.type === 'sizes' && (!Array.isArray(value) || !value.length || value.some(size => !allowedSizes.includes(size)))) return 'Укажите размеры из списка: XS, S, M, L, XL, XXL.';
    return '';
  }

  function errorsForAllFields() {
    const errors = new Map(uploadErrors);
    for (const definition of api.sectionDefinitions) {
      const target = { type: 'section', id: definition.id };
      for (const field of definition.fields) {
        const error = validateField(field, draft.sections[definition.id][field.key]);
        if (error) errors.set(fieldPath(field.key, target), error);
      }
    }
    for (const product of draft.products) {
      const target = { type: 'product', id: product.id };
      for (const field of productFields) {
        const error = validateField(field, product[field.key]);
        if (error) errors.set(fieldPath(field.key, target), error);
      }
    }
    return errors;
  }

  function updateStatus() {
    if (!ready) return;
    const changes = countChanges();
    dirty = changes > 0;
    const errors = errorsForAllFields();
    if (busy || uploads) {
      statusNode.dataset.state = 'busy';
      statusNode.textContent = uploads ? 'Подготавливаем фотографию…' : 'Сохраняем изменения…';
    } else if (errorNode.textContent || errors.size) {
      statusNode.dataset.state = 'error';
      statusNode.textContent = errors.size ? 'Проверьте поля перед сохранением' : 'Изменения не сохранены';
    } else if (dirty) {
      statusNode.dataset.state = 'dirty';
      statusNode.textContent = 'Есть несохранённые изменения';
    } else {
      statusNode.dataset.state = 'saved';
      statusNode.textContent = savedMessage;
    }
    saveButton.disabled = busy || uploads > 0 || !dirty || errors.size > 0;
    saveButton.querySelector('span').textContent = busy ? 'Сохраняем…' : 'Сохранить изменения';
    resetButton.disabled = busy || uploads > 0;
    $$('[data-cms-field], [data-cms-upload], [data-cms-image-reset], [data-cms-select], [data-cms-preview-photo], [data-cms-reset-confirm], [data-cms-reset-cancel]').forEach(control => {
      control.disabled = busy || uploads > 0;
    });
    workspace.setAttribute('aria-busy', String(busy || uploads > 0));
    for (const field of selectedFields()) {
      const message = errors.get(fieldPath(field.key)) || '';
      const input = $(`[data-cms-field="${field.key}"], [data-cms-upload="${field.key}"]`);
      if (input) input.setAttribute('aria-invalid', String(Boolean(message)));
      const error = $(`[data-cms-field-error="${field.key}"]`);
      if (error) error.textContent = message;
    }
  }

  function setBusy(value) {
    busy = value;
    updateStatus();
  }

  function renderNavigation() {
    $('[data-cms-section-count]').textContent = api.sectionDefinitions.length;
    $('[data-cms-product-count]').textContent = draft.products.length;
    $('[data-cms-sections]').innerHTML = api.sectionDefinitions.map((section, index) => `<button class="cms-list__button" type="button" data-cms-select="section:${section.id}" aria-pressed="false"><span class="cms-list__number">${String(index + 1).padStart(2, '0')}</span><span class="cms-list__name">${escapeHTML(section.title)}</span></button>`).join('');
    $('[data-cms-products]').innerHTML = draft.products.map(product => `<button class="cms-list__button" type="button" data-cms-select="product:${product.id}" aria-pressed="false"><img src="${escapeHTML(product.image)}" alt="" width="32" height="42" loading="lazy" /><span class="cms-list__name">${escapeHTML(product.title)}</span></button>`).join('');
    updateNavigation();
  }

  function updateNavigation() {
    const errors = errorsForAllFields();
    $$('[data-cms-select]').forEach(button => {
      button.setAttribute('aria-pressed', String(button.dataset.cmsSelect === `${selection.type}:${selection.id}`));
      if (button.dataset.cmsSelect.startsWith('product:')) {
        const product = draft.products.find(item => item.id === button.dataset.cmsSelect.slice(8));
        button.querySelector('.cms-list__name').textContent = product.title || 'Без названия';
        const image = button.querySelector('img');
        if (image.getAttribute('src') !== product.image) image.src = product.image;
      }
      const hasError = [...errors.keys()].some(path => path.startsWith(`${button.dataset.cmsSelect}:`));
      button.toggleAttribute('data-has-error', hasError);
      const name = button.querySelector('.cms-list__name').textContent;
      button.setAttribute('aria-label', hasError ? `${name}. Проверьте поля` : name);
    });
  }

  function renderFields() {
    const content = selectedContent();
    const targetFields = selectedFields();
    if (selection.type === 'section') {
      const index = api.sectionDefinitions.findIndex(section => section.id === selection.id);
      const definition = api.sectionDefinitions[index];
      $('[data-cms-editor-kicker]').textContent = `Главная страница / ${String(index + 1).padStart(2, '0')}`;
      $('#cms-editor-title').textContent = definition.title;
      $('[data-cms-editor-description]').textContent = `${definition.description} Результат виден в предпросмотре.`;
    } else {
      $('[data-cms-editor-kicker]').textContent = 'Каталог / Карточка товара';
      $('#cms-editor-title').textContent = 'Редактировать товар';
      $('[data-cms-editor-description]').textContent = 'Название, цена, описание и фотографии на всех страницах сайта.';
    }
    fieldsNode.innerHTML = targetFields.map(field => {
      const id = `cms-field-${field.key}`;
      const errorId = `${id}-error`;
      const value = content[field.key] ?? '';
      if (field.type === 'image') {
        return `<div class="cms-field cms-field--half"><span class="cms-field__label" id="${id}-label">${escapeHTML(field.label)}</span><div class="cms-image-field"><div class="cms-image-field__frame"><img src="${escapeHTML(value)}" alt="${escapeHTML(field.label)}" data-cms-field-image="${field.key}" /><span data-cms-file-note="${field.key}">Фото на сайте</span></div><label class="cms-upload-trigger" for="${id}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V3m-5 5 5-5 5 5M4 14v6h16v-6" /></svg><span>Загрузить фото</span><input id="${id}" type="file" accept="image/jpeg,image/png,image/webp" data-cms-upload="${field.key}" aria-labelledby="${id}-label" aria-describedby="${id}-hint ${errorId}" /></label><p class="cms-field__hint" id="${id}-hint">JPG, PNG или WebP · до 10 МБ</p><button class="cms-image-reset" type="button" data-cms-image-reset="${field.key}">Вернуть исходное фото</button><p class="cms-field__error" id="${errorId}" data-cms-field-error="${field.key}"></p></div></div>`;
      }
      const formattedValue = field.type === 'sizes' ? value.join(', ') : value;
      const hint = field.type === 'sizes' ? `<p class="cms-field__hint" id="${id}-hint">Через запятую: XS, S, M, L, XL, XXL</p>` : '';
      const describedBy = `${errorId}${hint ? ` ${id}-hint` : ''}`;
      const input = field.type === 'textarea'
        ? `<textarea id="${id}" data-cms-field="${field.key}" rows="4" maxlength="5000" aria-describedby="${describedBy}">${escapeHTML(formattedValue)}</textarea>`
        : `<input id="${id}" data-cms-field="${field.key}" type="${field.type === 'number' ? 'number' : 'text'}" value="${escapeHTML(formattedValue)}" ${field.type === 'number' ? 'min="0" max="10000000" step="1" inputmode="numeric"' : 'maxlength="200"'} ${field.key === 'title' ? 'required' : ''} aria-describedby="${describedBy}" />`;
      return `<div class="cms-field${field.half ? ' cms-field--half' : ''}"><label for="${id}">${escapeHTML(field.label)}</label>${input}${hint}<p class="cms-field__error" id="${errorId}" data-cms-field-error="${field.key}"></p></div>`;
    }).join('');
    renderPhotoSwitch();
    updateStatus();
  }

  function renderPhotoSwitch() {
    const container = $('[data-cms-photo-switch]');
    container.className = selection.type === 'product' ? 'cms-preview__photo-switch' : '';
    container.innerHTML = selection.type === 'product'
      ? `<button type="button" data-cms-preview-photo="image" aria-pressed="${previewPhoto === 'image'}">Основное фото</button><button type="button" data-cms-preview-photo="altImage" aria-pressed="${previewPhoto === 'altImage'}">Второй ракурс</button>`
      : '';
  }

  function renderPreview() {
    const content = selectedContent();
    const text = key => escapeHTML(content[key]);
    if (selection.type === 'product') {
      const price = Number(content.price);
      previewNode.innerHTML = `<article class="cms-mini-product"><div class="cms-mini-product__photo"><img src="${text(previewPhoto)}" alt="${text('title')}" />${content.label ? `<span class="cms-mini-product__badge">${text('label')}</span>` : ''}</div><div class="cms-mini-product__body"><span class="cms-mini-product__label">${content.category === 'hoodie' ? 'Худи / Вышивка' : 'Футболка / Арт'}</span><h3>${text('title') || 'Название товара'}</h3><p class="cms-mini-product__subtitle">${text('subtitle')}</p><strong class="cms-mini-product__price">${content.price !== '' && Number.isFinite(price) ? `${new Intl.NumberFormat('ru-RU').format(price)} ₽` : '— ₽'}</strong><div class="cms-mini-product__sizes">${content.sizes.map(size => `<span>${escapeHTML(size)}</span>`).join('')}</div><p class="cms-mini-product__description">${text('description')}</p></div></article>`;
      return;
    }
    const eyebrow = `<p class="cms-mini-block__eyebrow">${text('eyebrow')}</p>`;
    const heading = `<h3>${text('title') || 'Ваш заголовок'}${content.accent ? `<br><em>${text('accent')}</em>` : ''}${content.subtitle ? `<br><em>${text('subtitle')}</em>` : ''}</h3>`;
    const description = content.description ? `<p class="cms-mini-block__description">${text('description')}</p>` : '';
    const button = content.button ? `<span class="cms-mini-block__button">${text('button')}<i aria-hidden="true">↗</i></span>` : '';
    const photos = content.image ? `<div class="cms-mini-block__photos"><img src="${text('image')}" alt="Основная фотография" />${content.secondaryImage ? `<img src="${text('secondaryImage')}" alt="Вторая фотография" />` : ''}</div>` : '';
    if (selection.id === 'newsletter') {
      previewNode.innerHTML = `<div class="cms-mini-block cms-mini-block--newsletter">${eyebrow}${heading}<div class="cms-mini-email"><span>Ваш email</span><span aria-hidden="true">→</span></div></div>`;
    } else if (selection.id === 'campaign') {
      previewNode.innerHTML = `<div class="cms-mini-block cms-mini-block--campaign"><img src="${text('image')}" alt="Фотография коллекции" />${eyebrow}${heading}${description}${button}</div>`;
    } else {
      previewNode.innerHTML = `<div class="cms-mini-block">${eyebrow}${heading}${content.lead ? `<p class="cms-mini-block__lead">${text('lead')}</p>` : ''}${description}${button}${photos}</div>`;
    }
  }

  function selectItem(type, id) {
    if (!ready || busy || uploads) return;
    selection = { type, id };
    previewPhoto = 'image';
    updateNavigation();
    renderFields();
    renderPreview();
  }

  function applyEdit() {
    errorNode.textContent = '';
    updateNavigation();
    renderPreview();
    updateStatus();
  }

  function imageHeaderType(bytes) {
    if (bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) return 'image/jpeg';
    if ([137, 80, 78, 71, 13, 10, 26, 10].every((value, index) => bytes[index] === value)) return 'image/png';
    if (String.fromCharCode(...bytes.slice(0, 4)) === 'RIFF' && String.fromCharCode(...bytes.slice(8, 12)) === 'WEBP') return 'image/webp';
    return '';
  }

  async function preparePhoto(file) {
    if (file.size > 10 * 1024 * 1024) throw new Error('Фото больше 10 МБ. Выберите файл поменьше.');
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) throw new Error('Выберите фотографию в формате JPG, PNG или WebP.');
    const bytes = new Uint8Array(await file.slice(0, 16).arrayBuffer());
    if (!imageHeaderType(bytes)) throw new Error('Этот файл не похож на фотографию. Выберите JPG, PNG или WebP.');
    let picture;
    let objectURL;
    try {
      if (typeof createImageBitmap === 'function') picture = await createImageBitmap(file);
      else {
        objectURL = URL.createObjectURL(file);
        picture = await new Promise((resolve, reject) => {
          const image = new Image();
          image.onload = () => resolve(image);
          image.onerror = () => reject(new Error('Не удалось прочитать фотографию. Попробуйте другой файл.'));
          image.src = objectURL;
        });
      }
      const width = picture.width || picture.naturalWidth;
      const height = picture.height || picture.naturalHeight;
      if (!width || !height || width * height > 50000000) throw new Error('Фотография слишком большая. Выберите изображение меньшего размера.');
      const ratio = Math.min(1, 1600 / Math.max(width, height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(width * ratio));
      canvas.height = Math.max(1, Math.round(height * ratio));
      const context = canvas.getContext('2d');
      if (!context) throw new Error('Не удалось подготовить фотографию. Попробуйте снова.');
      context.drawImage(picture, 0, 0, canvas.width, canvas.height);
      const result = canvas.toDataURL('image/webp', .85);
      if (!/^data:image\/(?:webp|png);base64,/.test(result)) throw new Error('Не удалось подготовить фотографию. Попробуйте другой файл.');
      return result;
    } catch (error) {
      if (error instanceof Error && /^(Фотография|Не удалось)/.test(error.message)) throw error;
      throw new Error('Не удалось прочитать фотографию. Попробуйте другой файл.');
    } finally {
      picture?.close?.();
      if (objectURL) URL.revokeObjectURL(objectURL);
    }
  }

  workspace.addEventListener('click', event => {
    const select = event.target.closest('[data-cms-select]');
    if (select) {
      const [type, id] = select.dataset.cmsSelect.split(':');
      selectItem(type, id);
    }
    const photo = event.target.closest('[data-cms-preview-photo]');
    if (photo) {
      previewPhoto = photo.dataset.cmsPreviewPhoto;
      $$('[data-cms-preview-photo]').forEach(button => button.setAttribute('aria-pressed', String(button === photo)));
      renderPreview();
    }
    const imageReset = event.target.closest('[data-cms-image-reset]');
    if (imageReset && !busy && !uploads) {
      const key = imageReset.dataset.cmsImageReset;
      selectedContent()[key] = selectedContent(defaults)[key];
      uploadErrors.delete(fieldPath(key));
      const image = $(`[data-cms-field-image="${key}"]`);
      if (image) image.src = selectedContent()[key];
      $(`[data-cms-file-note="${key}"]`).textContent = 'Исходное фото';
      $(`[data-cms-upload="${key}"]`).value = '';
      applyEdit();
    }
  });

  form.addEventListener('input', event => {
    const input = event.target.closest('[data-cms-field]');
    if (!input || !ready || busy || uploads) return;
    const key = input.dataset.cmsField;
    const value = input.value;
    selectedContent()[key] = key === 'price' ? (value === '' ? '' : Number(value))
      : key === 'sizes' ? [...new Set(value.toUpperCase().split(/[,;\s]+/).filter(Boolean))] : value;
    applyEdit();
  });

  form.addEventListener('change', async event => {
    const input = event.target.closest('[data-cms-upload]');
    const file = input?.files?.[0];
    if (!file || !ready || busy || uploads) return;
    const target = { ...selection };
    const key = input.dataset.cmsUpload;
    const path = fieldPath(key, target);
    uploads += 1;
    uploadErrors.delete(path);
    errorNode.textContent = '';
    input.disabled = true;
    updateStatus();
    try {
      const image = await preparePhoto(file);
      selectedContent(draft, target)[key] = image;
      if (selection.type === target.type && selection.id === target.id) {
        $(`[data-cms-field-image="${key}"]`).src = image;
        $(`[data-cms-file-note="${key}"]`).textContent = 'Новое фото';
      }
    } catch (error) {
      uploadErrors.set(path, `${error.message} Фото не изменено.`);
    } finally {
      uploads -= 1;
      input.disabled = false;
      input.value = '';
      applyEdit();
    }
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!ready || busy || uploads || !dirty || errorsForAllFields().size) return;
    errorNode.textContent = '';
    setBusy(true);
    try {
      draft = await api.save(draft);
      baseline = clone(draft);
      const time = new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Moscow' }).format(new Date());
      savedMessage = `Изменения сохранены · ${time}`;
      renderNavigation();
      renderFields();
      renderPreview();
    } catch (error) {
      errorNode.textContent = error?.name === 'QuotaExceededError'
        ? 'Не хватает места для сохранения. Загрузите фотографии поменьше и попробуйте снова.'
        : 'Не удалось сохранить изменения. Проверьте, что браузер разрешает сохранение данных, и попробуйте снова.';
    } finally {
      setBusy(false);
    }
  });

  function closeReset() {
    resetPanel.hidden = true;
    resetButton.setAttribute('aria-expanded', 'false');
    resetButton.focus();
  }

  resetButton.addEventListener('click', () => {
    if (!ready || busy || uploads) return;
    resetPanel.hidden = false;
    resetButton.setAttribute('aria-expanded', 'true');
    $('[data-cms-reset-cancel]').focus();
    resetPanel.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'nearest' });
  });
  $('[data-cms-reset-cancel]').addEventListener('click', closeReset);
  resetPanel.addEventListener('keydown', event => { if (event.key === 'Escape' && !busy) closeReset(); });
  $('[data-cms-reset-confirm]').addEventListener('click', async () => {
    if (!ready || busy || uploads) return;
    errorNode.textContent = '';
    setBusy(true);
    try {
      draft = await api.reset();
      baseline = clone(draft);
      uploadErrors.clear();
      savedMessage = 'Демо сброшено. Исходный сайт восстановлен';
      renderNavigation();
      renderFields();
      renderPreview();
      closeReset();
    } catch {
      errorNode.textContent = 'Не удалось сбросить демо. Попробуйте снова — ваши изменения сохранены.';
    } finally {
      setBusy(false);
      if (resetPanel.hidden) resetButton.focus();
    }
  });

  window.addEventListener('beforeunload', event => {
    if (!dirty && !uploads) return;
    event.preventDefault();
    event.returnValue = '';
  });

  defaults = api.getDefaults();
  try {
    draft = await api.load();
  } catch {
    draft = clone(defaults);
    errorNode.textContent = 'Не удалось загрузить сохранённые изменения. Проверьте, что браузер разрешает сохранение данных, и обновите страницу.';
  }
  baseline = clone(draft);
  ready = true;
  renderNavigation();
  renderFields();
  renderPreview();
  workspace.setAttribute('aria-busy', 'false');
})();
