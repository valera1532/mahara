/* Single source for static pages and browser interactions */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.MaharaCatalog = factory();
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';
  const products = [
  {
    "id": "hoodie-black",
    "title": "Худи MAHARA Black",
    "subtitle": "Embroidery / Oversize",
    "category": "hoodie",
    "color": "black",
    "price": 16900,
    "image": "assets/images/black-hoodie.webp",
    "altImage": "assets/images/black-hoodie-woman.webp",
    "label": "New drop",
    "sizes": [
      "S",
      "M",
      "L",
      "XL",
      "XXL"
    ],
    "description": "Чёрное худи свободного кроя из плотного хлопка; авторская вышивка с архитектурными символами, мягкая изнанка и глубокий капюшон",
    "url": "product-hoodie-black.html",
    "width": 853,
    "height": 1280
  },
  {
    "id": "hoodie-green",
    "title": "Худи MAHARA Green",
    "subtitle": "Embroidery / Oversize",
    "category": "hoodie",
    "color": "green",
    "price": 16900,
    "image": "assets/images/green-hoodie.webp",
    "altImage": "assets/images/green-hoodie-back.webp",
    "label": "New drop",
    "sizes": [
      "S",
      "M",
      "L",
      "XL",
      "XXL"
    ],
    "description": "Тёмно-зелёное худи с вышивкой по всей поверхности; плотная ткань держит форму, а объёмный силуэт подходит для многослойных образов",
    "url": "product-hoodie-green.html",
    "width": 853,
    "height": 1280
  },
  {
    "id": "tee-slate",
    "title": "Футболка MAHARA Slate",
    "subtitle": "Embroidery / Heavy cotton",
    "category": "tee",
    "color": "slate",
    "price": 7900,
    "image": "assets/images/slate-tee.webp",
    "altImage": "assets/images/slate-tee-back.webp",
    "label": "New drop",
    "sizes": [
      "S",
      "M",
      "L",
      "XL",
      "XXL"
    ],
    "description": "Футболка из плотного хлопка со свободной посадкой и небольшой вышивкой с российскими архитектурными мотивами",
    "url": "product-tee-slate.html",
    "width": 853,
    "height": 1280
  },
  {
    "id": "tee-burgundy",
    "title": "Футболка Last Supper Burgundy",
    "subtitle": "Art print / Heavy cotton",
    "category": "tee",
    "color": "burgundy",
    "price": 6900,
    "image": "assets/images/burgundy-tee-angle.webp",
    "altImage": "assets/images/burgundy-tee.webp",
    "label": "Bestseller",
    "sizes": [
      "S",
      "M",
      "L",
      "XL"
    ],
    "description": "Бордовая футболка с крупным арт-принтом; плотный хлопок, свободный силуэт и изображение, которое считывается как самостоятельное высказывание",
    "url": "product-tee-burgundy.html",
    "width": 853,
    "height": 1280
  },
  {
    "id": "tee-white",
    "title": "Футболка Last Supper White",
    "subtitle": "Art print / Heavy cotton",
    "category": "tee",
    "color": "white",
    "price": 6900,
    "image": "assets/images/white-last-supper.webp",
    "altImage": "assets/images/white-last-supper.webp",
    "label": "New drop",
    "sizes": [
      "S",
      "M",
      "L",
      "XL"
    ],
    "description": "Белая футболка с контрастным арт-принтом, плотным воротом и свободной посадкой; базовый цвет делает графику главным акцентом",
    "url": "product-tee-white.html",
    "width": 853,
    "height": 1280
  },
  {
    "id": "tee-black",
    "title": "Футболка Last Supper Black",
    "subtitle": "Art print / Heavy cotton",
    "category": "tee",
    "color": "black",
    "price": 6900,
    "image": "assets/images/black-last-supper.webp",
    "altImage": "assets/images/black-last-supper.webp",
    "label": "Limited",
    "sizes": [
      "M",
      "L",
      "XL",
      "XXL"
    ],
    "description": "Чёрная футболка из плотного хлопка с крупным принтом; лимитированная модель из текущего дропа",
    "url": "product-tee-black.html",
    "width": 853,
    "height": 1280
  },
  {
    "id": "tee-green",
    "title": "Футболка Last Supper Green",
    "subtitle": "Art print / Heavy cotton",
    "category": "tee",
    "color": "green",
    "price": 6900,
    "image": "assets/images/green-tee.webp",
    "altImage": "assets/images/green-tee-seated.webp",
    "label": "New color",
    "sizes": [
      "S",
      "M",
      "L",
      "XL"
    ],
    "description": "Глубокий зелёный оттенок, свободная посадка и крупный арт-принт; футболка работает и как акцент, и как часть спокойного образа",
    "url": "product-tee-green.html",
    "width": 853,
    "height": 1280
  },
  {
    "id": "hoodie-black-female",
    "title": "Худи MAHARA Black II",
    "subtitle": "Unisex / Embroidery",
    "category": "hoodie",
    "color": "black",
    "price": 16900,
    "image": "assets/images/black-hoodie-woman.webp",
    "altImage": "assets/images/black-hoodie.webp",
    "label": "Unisex",
    "sizes": [
      "XS",
      "S",
      "M",
      "L",
      "XL"
    ],
    "description": "Унисекс-худи с расслабленной посадкой, объёмным капюшоном и вышивкой по всей поверхности; хорошо сидит на разном типе фигуры",
    "url": "product-hoodie-black-female.html",
    "width": 853,
    "height": 1280
  }
];
  const escape = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  function card(product) {
    const title = escape(product.title);
    const sizes = product.sizes.map((size, index) => `<button type="button" data-size-choice="${size}" class="${index === 1 ? 'is-active' : ''}" aria-pressed="${index === 1}" aria-label="Размер ${size}">${size}</button>`).join('');
    return `<article class="product-card reveal" data-product-id="${product.id}" data-category="${product.category}" data-color="${product.color}">
      <div class="product-card__media">
        <span class="product-card__badge">${escape(product.label)}</span>
        <button class="product-card__favorite js-only" type="button" data-favorite aria-pressed="false" aria-label="Добавить ${title} в избранное"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 4.7a5.6 5.6 0 0 0-7.9 0L12 5.6l-.9-.9a5.6 5.6 0 1 0-7.9 7.9l.9.9L12 21l7.9-7.5.9-.9a5.6 5.6 0 0 0 0-7.9Z"/></svg></button>
        <a class="product-card__image-link" href="${product.url}" aria-label="${title} — подробнее"><img class="product-card__image" src="${product.image}" alt="${title}" width="${product.width}" height="${product.height}" loading="lazy" decoding="async" /></a>
        <button class="product-card__quick js-only" type="button" data-quick-open aria-haspopup="dialog" aria-controls="quick-dialog" aria-label="Быстрый просмотр: ${title}">Быстрый просмотр</button>
      </div>
      <div class="product-card__body">
        <span class="product-card__label">${product.category === 'hoodie' ? 'Худи / Вышивка' : 'Футболка / Арт'}</span>
        <h3><a href="${product.url}">${title}</a></h3>
        <p class="product-card__subtitle">${escape(product.subtitle)}</p>
        <strong class="product-card__price">${new Intl.NumberFormat('ru-RU').format(product.price)} ₽</strong>
        <div class="product-card__sizes js-only" role="group" aria-label="Размер ${title}">${sizes}</div>
        <p class="product-card__available-sizes no-js-only">Размеры: ${product.sizes.join(', ')}</p>
        <button class="product-card__add js-only" type="button" data-add-cart aria-label="Добавить ${title} в корзину">В корзину</button>
      </div>
    </article>`;
  }
  return { products, card };
});
