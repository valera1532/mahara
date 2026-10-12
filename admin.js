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
  const logoutButton = $('[data-cms-logout]');
  const leaveDialog = $('[data-cms-leave-dialog]');
  const addProductButton = $('[data-cms-add-product]');
  const allowedSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
  const categories = [['hoodie', 'Худи'], ['tee', 'Футболка']];
  const colors = [['black', 'Чёрный'], ['green', 'Зелёный'], ['burgundy', 'Бордовый'], ['slate', 'Графитовый'], ['white', 'Белый']];
  const productFields = [
    { key: 'title', label: 'Название товара', type: 'text' },
    { key: 'category', label: 'Категория', type: 'select', options: categories, half: true },
    { key: 'color', label: 'Цвет', type: 'select', options: colors, half: true },
    { key: 'subtitle', label: 'Короткое описание', type: 'text' },
    { key: 'price', label: 'Цена, ₽', type: 'number', half: true },
    { key: 'label', label: 'Метка на карточке', type: 'text', half: true },
    { key: 'description', label: 'Описание товара', type: 'textarea' },
    { key: 'composition', label: 'Состав', type: 'textarea', maxLength: 2000, hint: 'Заполняйте по этикетке или данным изготовителя. Пока данных нет, оставьте поле пустым.' },
    { key: 'care', label: 'Уход', type: 'textarea', maxLength: 2000 },
    { key: 'manufacturer', label: 'Изготовитель и страна производства', type: 'textarea', maxLength: 2000 },
    { key: 'measurements', label: 'Замеры и посадка', type: 'textarea', maxLength: 2000 },
    { key: 'sizes', label: 'Доступные размеры', type: 'sizes' },
    { key: 'image', label: 'Основная фотография', type: 'image' },
    { key: 'altImage', label: 'Второй ракурс', type: 'image' }
  ];
  let draft;
  let baseline;
  let session;
  let selection = { type: 'section', id: 'hero' };
  let ready = false;
  let busy = false;
  let dirty = false;
  let uploads = 0;
  let navigatingAway = false;
  let savedMessage = 'Все изменения сохранены';
  let previewPhoto = 'image';
  const uploadErrors = new Map();
  let pendingDeparture;

  function blankProduct(id) {
    return {
      id, url: `product-${id}.html`, title: '', subtitle: '', category: 'hoodie', color: 'black',
      price: '', sizes: ['S', 'M', 'L', 'XL'], image: '', altImage: '', label: '', description: '',
      composition: '', care: '', manufacturer: '', measurements: '', width: 1200, height: 1500
    };
  }

  function loginRedirect() {
    dirty = false;
    navigatingAway = true;
    window.location.replace('/admin/login');
  }

  function humanError(error, fallback) {
    if (error?.status === 401) {
      loginRedirect();
      return 'Сессия завершилась. Войдите снова.';
    }
    if (error?.status === 409) return 'Сайт уже изменён в другой вкладке. Скопируйте свои правки и обновите страницу, чтобы загрузить актуальную версию.';
    if (error?.status === 403) return 'Не удалось подтвердить доступ. Обновите страницу и попробуйте снова.';
    if (error?.status === 413) return 'Файл слишком большой. Выберите фотографию до 10 МБ.';
    if (error?.status === 429) return 'Слишком много запросов. Подождите немного и попробуйте снова.';
    if (error?.status >= 400 && error?.status < 500 && error.message) return error.message;
    if (error?.name === 'TypeError') return 'Нет связи с сервером. Проверьте подключение и попробуйте снова.';
    return fallback;
  }

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

  function hasUnsavedEdits() {
    if (!ready) return false;
    for (const definition of api.sectionDefinitions) {
      for (const field of definition.fields) if (!sameValue(draft.sections[definition.id][field.key], baseline.sections[definition.id][field.key])) return true;
    }
    for (const product of draft.products) {
      // Opening an empty card is not an edit that needs to block navigation.
      const original = baseline.products.find(item => item.id === product.id) || blankProduct(product.id);
      for (const field of productFields) if (!sameValue(product[field.key], original[field.key])) return true;
    }
    return false;
  }

  function countChanges() {
    let count = 0;
    for (const definition of api.sectionDefinitions) {
      for (const field of definition.fields) if (!sameValue(draft.sections[definition.id][field.key], baseline.sections[definition.id][field.key])) count += 1;
    }
    for (const product of draft.products) {
      const original = baseline.products.find(item => item.id === product.id);
      if (!original) { count += 1; continue; }
      for (const field of productFields) if (!sameValue(product[field.key], original[field.key])) count += 1;
    }
    return count;
  }

  function validateField(field, value) {
    if (field.maxLength && String(value ?? '').length > field.maxLength) return `Не больше ${field.maxLength} символов.`;
    if (field.key === 'title' && !String(value ?? '').trim()) return 'Введите заголовок.';
    if (field.type === 'select' && !field.options.some(option => option[0] === value)) return 'Выберите значение из списка.';
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
        const error = field.key === 'image' && !product.image ? 'Загрузите основную фотографию товара.' : validateField(field, product[field.key]);
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
      statusNode.textContent = uploads ? 'Загружаем фотографию на сервер…' : 'Сохраняем изменения…';
    } else if (errorNode.textContent || errors.size) {
      statusNode.dataset.state = 'error';
      statusNode.textContent = errors.size ? 'Проверьте поля перед сохранением' : 'Изменения не сохранены';
    } else if (dirty) {
      statusNode.dataset.state = 'dirty';
      statusNode.textContent = `Несохранённых изменений: ${changes}`;
    } else {
      statusNode.dataset.state = 'saved';
      statusNode.textContent = savedMessage;
    }
    saveButton.disabled = busy || uploads > 0 || !dirty || errors.size > 0;
    saveButton.querySelector('span').textContent = busy ? 'Сохраняем…' : 'Сохранить изменения';
    resetButton.disabled = busy || uploads > 0 || !dirty;
    logoutButton.disabled = busy || uploads > 0;
    addProductButton.disabled = busy || uploads > 0;
    $$('[data-cms-field], [data-cms-upload], [data-cms-image-reset], [data-cms-select], [data-cms-preview-photo], [data-cms-reset-confirm], [data-cms-reset-cancel]').forEach(control => {
      control.disabled = busy || uploads > 0;
      if (control.hasAttribute('data-cms-image-reset') && !selectedContent()[control.dataset.cmsImageReset] && !uploadErrors.has(fieldPath(control.dataset.cmsImageReset))) control.disabled = true;
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
    $('[data-cms-products]').innerHTML = draft.products.map(product => `<button class="cms-list__button" type="button" data-cms-select="product:${escapeHTML(product.id)}" aria-pressed="false"><img ${product.image ? `src="${escapeHTML(product.image)}"` : 'hidden'} alt="" width="32" height="42" loading="lazy" /><span class="cms-list__name">${escapeHTML(product.title || 'Новый товар')}</span></button>`).join('');
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
        image.hidden = !product.image;
        if (product.image && image.getAttribute('src') !== product.image) image.src = product.image;
        else if (!product.image) image.removeAttribute('src');
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
      const isNew = !baseline.products.some(product => product.id === selection.id);
      $('#cms-editor-title').textContent = isNew ? 'Новый товар' : 'Редактировать товар';
      $('[data-cms-editor-description]').textContent = isNew ? 'Заполните название, категорию, цвет, цену, размеры и загрузите фото. Состав, уход и замеры добавьте по данным изготовителя. После сохранения товар появится в каталоге.' : 'Описание, состав, уход, изготовитель, замеры и фотографии на всех страницах сайта.';
    }
    fieldsNode.innerHTML = targetFields.map(field => {
      const id = `cms-field-${field.key}`;
      const errorId = `${id}-error`;
      const value = content[field.key] ?? '';
      if (field.type === 'image') {
        const savedPhoto = selectedContent(baseline)?.[field.key];
        return `<div class="cms-field cms-field--half"><span class="cms-field__label" id="${id}-label">${escapeHTML(field.label)}</span><div class="cms-image-field"><div class="cms-image-field__frame"><img ${value ? `src="${escapeHTML(value)}"` : 'hidden'} alt="${escapeHTML(field.label)}" data-cms-field-image="${field.key}" /><span data-cms-file-note="${field.key}">${value ? 'Фото товара' : 'Фото не загружено'}</span></div><label class="cms-upload-trigger" for="${id}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V3m-5 5 5-5 5 5M4 14v6h16v-6" /></svg><span>Загрузить фото</span><input id="${id}" type="file" accept="image/jpeg,image/png,image/webp" data-cms-upload="${field.key}" aria-labelledby="${id}-label" aria-describedby="${id}-hint ${errorId}" /></label><p class="cms-field__hint" id="${id}-hint">JPG, PNG или WebP · до 10 МБ${field.key === 'altImage' ? '<br>Без второго ракурса используем основное фото.' : ''}</p><button class="cms-image-reset" type="button" data-cms-image-reset="${field.key}">${savedPhoto ? 'Вернуть сохранённое фото' : 'Убрать фото'}</button><p class="cms-field__error" id="${errorId}" data-cms-field-error="${field.key}"></p></div></div>`;
      }
      const formattedValue = field.type === 'sizes' ? value.join(', ') : value;
      const hintText = field.type === 'sizes' ? 'Через запятую: XS, S, M, L, XL, XXL' : field.hint;
      const hint = hintText ? `<p class="cms-field__hint" id="${id}-hint">${escapeHTML(hintText)}</p>` : '';
      const describedBy = `${errorId}${hint ? ` ${id}-hint` : ''}`;
      const input = field.type === 'textarea'
        ? `<textarea id="${id}" data-cms-field="${field.key}" rows="4" maxlength="${field.maxLength || 5000}" aria-describedby="${describedBy}">${escapeHTML(formattedValue)}</textarea>`
        : field.type === 'select'
          ? `<select id="${id}" data-cms-field="${field.key}" required aria-describedby="${describedBy}">${field.options.map(([optionValue, label]) => `<option value="${optionValue}" ${value === optionValue ? 'selected' : ''}>${label}</option>`).join('')}</select>`
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
      const photo = content[previewPhoto] || content.image;
      const facts = productFields.filter(field => field.maxLength && content[field.key]).map(field => `<p class="cms-mini-product__description" data-cms-preview-fact="${field.key}"><strong>${escapeHTML(field.label)}:</strong><br>${text(field.key)}</p>`).join('');
      previewNode.innerHTML = `<article class="cms-mini-product"><div class="cms-mini-product__photo">${photo ? `<img src="${escapeHTML(photo)}" alt="${text('title')}" />` : '<div class="cms-mini-product__placeholder">Загрузите фотографию товара</div>'}${content.label ? `<span class="cms-mini-product__badge">${text('label')}</span>` : ''}</div><div class="cms-mini-product__body"><span class="cms-mini-product__label">${content.category === 'hoodie' ? 'Худи' : 'Футболка'} / ${escapeHTML(colors.find(color => color[0] === content.color)?.[1] || '')}</span><h3>${text('title') || 'Название товара'}</h3><p class="cms-mini-product__subtitle">${text('subtitle')}</p><strong class="cms-mini-product__price">${content.price !== '' && Number.isFinite(price) ? `${new Intl.NumberFormat('ru-RU').format(price)} ₽` : '— ₽'}</strong><div class="cms-mini-product__sizes">${content.sizes.map(size => `<span>${escapeHTML(size)}</span>`).join('')}</div><p class="cms-mini-product__description">${text('description')}</p>${facts}</div></article>`;
      return;
    }
    const eyebrow = `<p class="cms-mini-block__eyebrow">${text('eyebrow')}</p>`;
    const heading = `<h3>${text('title') || 'Ваш заголовок'}${content.accent ? `<br><em>${text('accent')}</em>` : ''}${content.subtitle ? `<br><em>${text('subtitle')}</em>` : ''}</h3>`;
    const description = content.description ? `<p class="cms-mini-block__description">${text('description')}</p>` : '';
    const button = content.button ? `<span class="cms-mini-block__button">${text('button')}<i aria-hidden="true">↗</i></span>` : '';
    const photos = content.image ? `<div class="cms-mini-block__photos"><img src="${text('image')}" alt="Основная фотография" />${content.secondaryImage ? `<img src="${text('secondaryImage')}" alt="Вторая фотография" />` : ''}</div>` : '';
    if (selection.id === 'newsletter') {
      previewNode.innerHTML = `<div class="cms-mini-block cms-mini-block--newsletter">${eyebrow}${heading}<p class="cms-mini-block__description">Новости коллекций — в наших социальных сетях.</p><div class="cms-mini-email"><span>Telegram ↗</span><span>VK ↗</span></div></div>`;
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
    if (imageHeaderType(bytes) !== file.type) throw new Error('Этот файл не похож на фотографию. Выберите JPG, PNG или WebP.');
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
      const result = await new Promise(resolve => canvas.toBlob(resolve, 'image/webp', .85));
      if (!result) throw new Error('Не удалось подготовить фотографию. Попробуйте другой файл.');
      return { file: result, width: canvas.width, height: canvas.height };
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
      const original = selectedContent(baseline);
      selectedContent()[key] = original?.[key] || '';
      if (selection.type === 'product' && key === 'image') {
        selectedContent().width = original?.width || 1200;
        selectedContent().height = original?.height || 1500;
      }
      uploadErrors.delete(fieldPath(key));
      const image = $(`[data-cms-field-image="${key}"]`);
      if (image) {
        image.hidden = !selectedContent()[key];
        if (selectedContent()[key]) image.src = selectedContent()[key];
        else image.removeAttribute('src');
      }
      $(`[data-cms-file-note="${key}"]`).textContent = selectedContent()[key] ? 'Сохранённое фото' : 'Фото не загружено';
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
    const uploadDraft = draft;
    const key = input.dataset.cmsUpload;
    const path = fieldPath(key, target);
    uploads += 1;
    uploadErrors.delete(path);
    errorNode.textContent = '';
    input.disabled = true;
    updateStatus();
    try {
      const prepared = await preparePhoto(file);
      if (navigatingAway || draft !== uploadDraft) return;
      const uploaded = await api.upload(prepared.file);
      if (navigatingAway || draft !== uploadDraft || !selectedContent(draft, target)) return;
      const image = uploaded.url;
      if (!image) throw new Error('Не удалось загрузить фотографию на сервер. Попробуйте снова.');
      selectedContent(draft, target)[key] = image;
      if (target.type === 'product' && key === 'image') {
        selectedContent(draft, target).width = prepared.width;
        selectedContent(draft, target).height = prepared.height;
      }
      if (selection.type === target.type && selection.id === target.id) {
        $(`[data-cms-field-image="${key}"]`).src = image;
        $(`[data-cms-field-image="${key}"]`).hidden = false;
        $(`[data-cms-file-note="${key}"]`).textContent = 'Новое фото';
      }
    } catch (error) {
      const message = error?.status || error?.name === 'TypeError'
        ? humanError(error, 'Не удалось загрузить фотографию. Попробуйте снова.')
        : error.message || 'Не удалось подготовить фотографию. Попробуйте другой файл.';
      uploadErrors.set(path, `${message} Фото не изменено.`);
    } finally {
      uploads -= 1;
      input.disabled = false;
      input.value = '';
      if (!navigatingAway && draft === uploadDraft) applyEdit();
    }
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!ready || busy || uploads || !dirty || errorsForAllFields().size) return;
    errorNode.textContent = '';
    setBusy(true);
    try {
      const payload = clone(draft);
      for (const product of payload.products) if (!product.altImage) product.altImage = product.image;
      draft = await api.save(payload);
      baseline = clone(draft);
      uploadErrors.clear();
      const time = new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Moscow' }).format(new Date());
      savedMessage = `Изменения сохранены · ${time}`;
      renderNavigation();
      renderFields();
      renderPreview();
    } catch (error) {
      errorNode.textContent = humanError(error, 'Не удалось сохранить изменения на сервере. Попробуйте снова — ваши правки остаются в черновике.');
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
    if (!ready || busy || uploads || !dirty) return;
    resetPanel.hidden = false;
    resetButton.setAttribute('aria-expanded', 'true');
    $('[data-cms-reset-cancel]').focus();
    resetPanel.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'nearest' });
  });
  $('[data-cms-reset-cancel]').addEventListener('click', closeReset);
  resetPanel.addEventListener('keydown', event => { if (event.key === 'Escape' && !busy) closeReset(); });
  $('[data-cms-reset-confirm]').addEventListener('click', () => {
    if (!ready || busy || uploads) return;
    errorNode.textContent = '';
    draft = clone(baseline);
    if (!selectedContent()) selection = { type: 'section', id: 'hero' };
    uploadErrors.clear();
    savedMessage = 'Черновик отменён. Открыт последний сохранённый вариант';
    renderNavigation();
    renderFields();
    renderPreview();
    closeReset();
    updateStatus();
  });

  addProductButton.addEventListener('click', () => {
    if (!ready || busy || uploads) return;
    const id = `item-${crypto.randomUUID()}`;
    draft.products.push(blankProduct(id));
    errorNode.textContent = '';
    selection = { type: 'product', id };
    previewPhoto = 'image';
    renderNavigation();
    renderFields();
    renderPreview();
    $('[data-cms-field="title"]').focus();
  });

  function closeDeparture() {
    pendingDeparture = undefined;
    leaveDialog.close();
  }

  $('[data-cms-leave-cancel]').addEventListener('click', closeDeparture);
  leaveDialog.addEventListener('cancel', () => { pendingDeparture = undefined; });
  $('[data-cms-leave-confirm]').addEventListener('click', () => {
    const destination = pendingDeparture;
    closeDeparture();
    if (!destination) return;
    navigatingAway = true;
    window.location.assign(destination);
  });

  document.addEventListener('click', event => {
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
    const destination = new URL(link.href, window.location.href);
    if (destination.origin !== window.location.origin || !/^https?:$/.test(destination.protocol)) return;
    if (destination.pathname === window.location.pathname && destination.search === window.location.search && destination.hash) return;
    if (navigatingAway || (!uploads && !hasUnsavedEdits())) return;
    event.preventDefault();
    pendingDeparture = destination.href;
    if (!leaveDialog.open) leaveDialog.showModal();
  });

  logoutButton.addEventListener('click', async () => {
    if (busy || uploads || (hasUnsavedEdits() && !window.confirm('Выйти без сохранения изменений?'))) return;
    setBusy(true);
    try {
      const response = await fetch('/api/admin/logout', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': session.csrfToken } });
      if (!response.ok && response.status !== 401) {
        const error = new Error();
        error.status = response.status;
        throw error;
      }
      loginRedirect();
    } catch (error) {
      errorNode.textContent = humanError(error, 'Не удалось завершить сеанс. Проверьте подключение и попробуйте снова.');
      setBusy(false);
    }
  });

  window.addEventListener('beforeunload', event => {
    if (navigatingAway || (!hasUnsavedEdits() && !uploads)) return;
    event.preventDefault();
    event.returnValue = '';
  });

  try {
    const response = await fetch('/api/admin/session', { credentials: 'same-origin', cache: 'no-store' });
    if (response.status === 401) { loginRedirect(); return; }
    if (!response.ok) throw new Error('Не удалось проверить доступ.');
    session = await response.json();
    if (!session.user || !session.csrfToken) throw new Error('Не удалось проверить доступ.');
    $('[data-cms-user]').textContent = session.user.username;
    logoutButton.disabled = false;
    draft = await api.load();
  } catch (error) {
    statusNode.dataset.state = 'error';
    statusNode.textContent = 'Не удалось загрузить содержимое сайта';
    errorNode.textContent = humanError(error, 'Сервер недоступен. Обновите страницу и попробуйте снова.');
    fieldsNode.innerHTML = '<p class="cms-loading">Содержимое не загружено. <a href="/admin.html">Попробовать снова →</a></p>';
    previewNode.textContent = '';
    workspace.setAttribute('aria-busy', 'false');
    return;
  }
  baseline = clone(draft);
  ready = true;
  const initialQuery = new URLSearchParams(window.location.search);
  const requestedProduct = initialQuery.get('product');
  if (requestedProduct && draft.products.some(product => product.id === requestedProduct)) {
    selection = { type: 'product', id: requestedProduct };
  }
  renderNavigation();
  renderFields();
  renderPreview();
  workspace.setAttribute('aria-busy', 'false');
  if (initialQuery.get('newProduct') === '1') {
    const cleanURL = new URL(window.location.href);
    cleanURL.searchParams.delete('newProduct');
    window.history.replaceState(window.history.state, '', cleanURL.pathname + cleanURL.search + cleanURL.hash);
    addProductButton.click();
  }
})();
