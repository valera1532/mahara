'use strict';

// No framework or build service: node tools/build-seo.cjs
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const config = require('../seo.config.json');
const { products, card } = require('../catalog-data.js');
const origin = new URL(config.siteUrl);
if (origin.protocol !== 'https:' || origin.search || origin.hash || origin.pathname !== '/') {
  throw new Error('siteUrl must be the HTTPS origin, with a trailing slash');
}
const url = file => new URL(file === 'index.html' ? '' : file, origin).href;
const escape = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const write = (file, value) => fs.writeFileSync(path.join(root, file), value, 'utf8');
const brand = { '@type': 'Brand', name: config.brand };
const organization = { '@type': 'Organization', '@id': `${origin}#organization`, name: config.brand, url: origin.href, logo: url('assets/icons/mahara-logo.svg') };
const website = { '@type': 'WebSite', '@id': `${origin}#website`, name: config.brand, url: origin.href, inLanguage: 'ru-RU', publisher: { '@id': organization['@id'] } };
const colorNames = { black: 'Чёрный', green: 'Зелёный', white: 'Белый', slate: 'Серо-синий', burgundy: 'Бордовый' };
const crumbs = items => ({ '@type': 'BreadcrumbList', itemListElement: items.map(([name, file], i) => ({ '@type': 'ListItem', position: i + 1, name, item: url(file) })) });

function head({ file, title, description, image = 'assets/images/social-cover.jpg', imageWidth = 1200, imageHeight = 630, graph, type = 'website' }) {
  const galleryStyles = file.startsWith('product-') ? '\n  <link rel="stylesheet" href="/assets/vendor/swiper/swiper-bundle.min.css?v=14.3.0" />\n  <link rel="stylesheet" href="/product-gallery.css?v=20261015-1" />' : '';
  const galleryScripts = file.startsWith('product-') ? '\n  <script defer src="/assets/vendor/swiper/swiper-bundle.min.js?v=14.3.0"></script>\n  <script defer src="/product-gallery.js?v=20261015-2"></script>' : '';
  const cmsVersion = '20261015-2';
  return `<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="theme-color" content="#050505" />
  <title>${escape(title)}</title>
  <meta name="description" content="${escape(description)}" />
  <meta name="robots" content="index, follow, max-image-preview:large" />
  <link rel="canonical" href="${url(file)}" />
  <meta property="og:locale" content="${config.locale}" />
  <meta property="og:type" content="${type}" />
  <meta property="og:site_name" content="MAHARA" />
  <meta property="og:title" content="${escape(title)}" />
  <meta property="og:description" content="${escape(description)}" />
  <meta property="og:url" content="${url(file)}" />
  <meta property="og:image" content="${url(image)}" />
  <meta property="og:image:width" content="${imageWidth}" />
  <meta property="og:image:height" content="${imageHeight}" />
  <meta property="og:image:alt" content="${escape(file.startsWith('product-') ? title : 'MAHARA — одежда с авторской вышивкой и арт-принтами')}" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="${escape(title)}" />
  <meta name="twitter:description" content="${escape(description)}" />
  <meta name="twitter:image" content="${url(image)}" />
  <meta name="twitter:image:alt" content="${escape(title)}" />
  <link rel="icon" href="assets/icons/favicon.svg?v=20261019-1" type="image/svg+xml" />
  <link rel="stylesheet" href="styles.css?v=20261019-1" />${galleryStyles}
  <script type="application/ld+json">${JSON.stringify({ '@context': 'https://schema.org', '@graph': graph }).replace(/</g, '\\u003c')}</script>
  <script defer src="catalog-data.js?v=20261011-2"></script>${galleryScripts}
  <script defer src="cms-data.js?v=${cmsVersion}"></script>
  <script defer src="script.js?v=20261012-1"></script>
</head>`;
}

function productImages(product) {
  const sources = Array.isArray(product.images) && product.images.length ? product.images : [product.image, product.altImage];
  return [...new Set(sources.filter(image => typeof image === 'string' && image.trim()))].slice(0, 10);
}

function productGallery(product) {
  const images = productImages(product);
  const size = `width="${escape(product.width)}" height="${escape(product.height)}"`;
  const slides = images.map((image, index) => `<div class="swiper-slide${index === 0 ? ' is-gallery-active' : ''}"><img src="${escape(image)}" alt="${escape(product.title + (index === 0 ? '' : ' — другой ракурс'))}" ${size} ${index === 0 ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"'} decoding="async" /></div>`).join('');
  const thumbs = images.map((image, index) => `<button class="product-gallery__thumb" type="button" data-gallery-thumb="${index}" aria-label="Показать фото ${index + 1}" aria-pressed="${index === 0}"><img src="${escape(image)}" alt="" ${size} loading="lazy" decoding="async" /></button>`).join('');
  const controls = images.length > 1 ? `<div class="product-gallery__controls"><button type="button" data-gallery-prev aria-label="Предыдущее фото" disabled><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg></button><div class="product-gallery__counter" aria-live="polite" aria-atomic="true"><span data-gallery-current>01</span><span aria-hidden="true"> / </span><span data-gallery-total>${String(images.length).padStart(2, '0')}</span></div><button type="button" data-gallery-next aria-label="Следующее фото"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m10 6 6 6-6 6"/></svg></button></div><div class="product-gallery__thumbs" role="group" aria-label="Выбор фотографии">${thumbs}</div>` : '';
  return `<div class="product-detail__gallery" data-product-gallery role="region" aria-label="Фотографии товара"${images.length > 1 ? ' tabindex="0"' : ''}><div class="product-gallery__viewport swiper"><div class="swiper-wrapper">${slides}</div></div>${controls}</div>`;
}

function updatePage(file, metadata, type, selectedProducts) {
  let html = read(file).replace(/<head>[\s\S]*?<\/head>/, head({ file, ...metadata }));
  html = html.replace(/\s*<script src="script\.js[^\"]*"><\/script>/g, '');
  if (type) {
    const marker = new RegExp(`<!-- products:${type} -->[\\s\\S]*?<!-- /products:${type} -->`);
    if (!marker.test(html)) throw new Error(`Missing product marker in ${file}`);
    html = html.replace(marker, `<!-- products:${type} -->\n${selectedProducts.map(p => card(p)).join('\n')}\n<!-- /products:${type} -->`);
  }
  write(file, html);
}

updatePage('index.html', {
  title: 'MAHARA — российский бренд одежды | Худи и футболки',
  description: 'MAHARA — уличная одежда, созданная в России: худи с авторской вышивкой и футболки с арт-принтами, свободная посадка и малые тиражи',
  graph: [organization, website, { '@type': 'WebPage', '@id': `${url('index.html')}#page`, url: url('index.html'), name: 'MAHARA — российский бренд одежды', inLanguage: 'ru-RU', isPartOf: { '@id': website['@id'] }, about: { '@id': organization['@id'] } }]
}, 'featured', products.slice(0, 4));

updatePage('catalog.html', {
  title: 'Каталог MAHARA — худи с вышивкой и футболки с принтами',
  description: 'Каталог одежды MAHARA: худи с архитектурной вышивкой, футболки Last Supper и MAHARA Slate, цвета и размеры моделей текущего дропа',
  graph: [website, { '@type': 'CollectionPage', '@id': `${url('catalog.html')}#page`, url: url('catalog.html'), name: 'Каталог одежды MAHARA', inLanguage: 'ru-RU', isPartOf: { '@id': website['@id'] }, mainEntity: { '@type': 'ItemList', numberOfItems: products.length, itemListElement: products.map((p, i) => ({ '@type': 'ListItem', position: i + 1, name: p.title, url: url(p.url) })) } }, crumbs([['Главная', 'index.html'], ['Каталог', 'catalog.html']])]
}, 'catalog', products);

// Shared chrome is taken from the editable catalog, so dialogs/nav stay consistent.
const catalog = read('catalog.html');
const chromeStart = catalog.slice(catalog.indexOf('<body'), catalog.indexOf('  <main'))
  .replace('class="page-catalog"', 'class="page-product"').replace(' class="is-active" href="catalog.html" aria-current="page"', ' href="catalog.html"');
const chromeEnd = catalog.slice(catalog.indexOf('  <footer'));

for (const product of products) {
  const title = `${product.title} — ${product.category === 'hoodie' ? 'худи с вышивкой' : 'футболка из хлопка'} | MAHARA`;
  const description = `${product.description}; размеры ${product.sizes.join(', ')}`;
  const category = product.category === 'hoodie' ? 'Худи' : 'Футболки';
  // Demonstration prices are deliberately excluded from structured data.
  const schemaProduct = { '@type': 'Product', '@id': `${url(product.url)}#product`, name: product.title, url: url(product.url), description: product.description, image: productImages(product).map(url), brand, category, color: colorNames[product.color], size: product.sizes };
  const pageHead = head({ file: product.url, title, description, image: productImages(product)[0] || product.image, imageWidth: product.width, imageHeight: product.height, graph: [schemaProduct, { '@type': 'WebPage', '@id': `${url(product.url)}#page`, name: product.title, url: url(product.url), inLanguage: 'ru-RU', mainEntity: { '@id': schemaProduct['@id'] }, isPartOf: { '@id': website['@id'] } }, crumbs([['Главная', 'index.html'], ['Каталог', 'catalog.html'], [product.title, product.url]])] });
  const sizes = product.sizes.map((size, i) => `<button type="button" data-size-choice="${size}" class="${i === 0 ? 'is-active' : ''}" aria-pressed="${i === 0}" aria-label="Размер ${size}">${size}</button>`).join('');
  const main = `  <main class="product-main" id="main-content" tabindex="-1">
    <div class="container">
      <nav class="breadcrumbs" aria-label="Хлебные крошки"><a href="index.html">Главная</a><span aria-hidden="true">/</span><a href="catalog.html">Каталог</a><span aria-hidden="true">/</span><span aria-current="page">${escape(product.title)}</span></nav>
      <article class="product-detail" data-product-detail data-product-id="${product.id}" aria-labelledby="product-title">
        ${productGallery(product)}
        <div class="product-detail__copy">
          <p class="eyebrow">${category} / MAHARA</p>
          <h1 id="product-title">${escape(product.title)}</h1>
          <p class="product-detail__description">${escape(product.description)}</p>
          <strong class="product-detail__price">${new Intl.NumberFormat('ru-RU').format(product.price)} ₽</strong>
          <dl class="product-detail__facts"><div><dt>Цвет</dt><dd>${colorNames[product.color]}</dd></div><div><dt>Размеры</dt><dd>${product.sizes.join(', ')}</dd></div><div><dt>Линия</dt><dd>${escape(product.subtitle)}</dd></div></dl>
          <div class="product-card__sizes js-only" role="group" aria-label="Выбрать размер">${sizes}</div>
          <button class="button button--primary js-only" type="button" data-add-cart aria-label="Добавить ${escape(product.title)} в корзину">Добавить в корзину <span aria-hidden="true">↗</span></button>
          <a class="product-detail__back" href="catalog.html">Вернуться в каталог <span aria-hidden="true">→</span></a>
        </div>
      </article>
    </div>
  </main>\n`;
  write(product.url, `<!doctype html>\n<html lang="ru">\n${pageHead}\n${chromeStart}${main}${chromeEnd}`);
}

const pages = ['index.html', 'catalog.html', ...products.map(p => p.url)];
write('sitemap.xml', `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${pages.map(file => `  <url><loc>${escape(url(file))}</loc></url>`).join('\n')}\n</urlset>\n`);
write('robots.txt', `User-agent: *\nAllow: /\nDisallow: /preview/\nDisallow: /tools/\nSitemap: ${url('sitemap.xml')}\n`);
console.log(`Built ${pages.length} pages for ${origin.href}`);
