<?php
declare(strict_types=1);

/** Render published content into the existing, editable storefront templates. */
function renderPage(string $pathname, array $content): ?string
{
    $route = parse_url($pathname, PHP_URL_PATH);
    if (!is_string($route)) return null;
    $route = ltrim($route, '/');
    if ($route === '') $route = 'index.html';
    if (!in_array($route, ['index.html', 'catalog.html', 'sitemap.xml', 'robots.txt'], true)
        && !preg_match('/^product-[a-z0-9][a-z0-9-]{0,79}\.html$/D', $route)) return null;

    $root = dirname(__DIR__);
    $defaults = json_decode((string) file_get_contents($root . '/site-defaults.json'), true, 512, JSON_THROW_ON_ERROR);
    $sections = [];
    foreach ($defaults['sections'] as $name => $fields) {
        $sections[$name] = array_replace($fields, is_array($content['sections'][$name] ?? null) ? $content['sections'][$name] : []);
    }
    // An empty published catalog is intentional; never bring deleted products back.
    $products = array_values(is_array($content['products'] ?? null) ? $content['products'] : $defaults['products']);
    $published = ['version' => 1, 'sections' => $sections, 'products' => $products, 'revision' => (int) ($content['revision'] ?? 1)];
    $config = json_decode((string) file_get_contents($root . '/seo.config.json'), true, 512, JSON_THROW_ON_ERROR);
    $origin = mhRenderOrigin($config['siteUrl'] ?? 'https://maharussia.ru/');
    $url = static fn(string $file): string => $origin . ($file === 'index.html' ? '' : ltrim($file, '/'));

    if ($route === 'robots.txt') {
        return "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /checkout\nDisallow: /inventory.html\nDisallow: /orders.html\nDisallow: /operations.html\nDisallow: /api/\nDisallow: /server/\nDisallow: /tests/\nDisallow: /preview/\nDisallow: /tools/\nSitemap: " . $url('sitemap.xml') . "\n";
    }
    if ($route === 'sitemap.xml') {
        $files = array_merge(['index.html', 'catalog.html'], array_column($products, 'url'));
        if (function_exists('mahara_merchant_config')) {
            foreach (mahara_legal_content(mahara_merchant_config()) as $name => $document) if (!$document['draft']) $files[] = $name . '.html';
            if (mahara_merchant_legal_status()['ready']) $files[] = 'contacts.html';
        }
        $locations = array_map(static fn(string $file): string => '  <url><loc>' . mhRenderEscape($url($file)) . '</loc></url>', array_unique($files));
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n" . implode("\n", $locations) . "\n</urlset>\n";
    }

    $product = null;
    if (str_starts_with($route, 'product-')) {
        foreach ($products as $item) {
            if (($item['url'] ?? '') === $route) { $product = $item; break; }
        }
        if ($product === null) return null;
    }
    $template = $product !== null ? 'product-hoodie-black.html' : $route;
    $html = mhRenderTemplate($template);
    if ($product !== null) {
        $html = mhRenderReplace($html, '~<main\b[\s\S]*?</main>~', mhRenderProduct($product));
    } else {
        $type = $route === 'index.html' ? 'featured' : 'catalog';
        $cards = implode("\n", array_map('mhRenderCard', $type === 'featured' ? array_slice($products, 0, 4) : $products));
        $html = mhRenderReplace($html, '~<!-- products:' . $type . ' -->[\s\S]*?<!-- /products:' . $type . ' -->~', "<!-- products:$type -->\n$cards\n<!-- /products:$type -->");
        if ($route === 'index.html') $html = mhRenderHome($html, $sections);
        else $html = mhRenderReplace($html, '~(<span\b[^>]*data-results-count\b[^>]*>)[\s\S]*?(</span>)~', (string) count($products) . ' ' . mhRenderProductNoun(count($products)), true);
    }
    $html = mhRenderReplace($html, '~<head>[\s\S]*?</head>~', mhRenderHead($route, $sections, $products, $product, $config, $origin));
    // JSON is escaped for the HTML raw-text context, not HTML entity encoded.
    $bootstrap = '<script type="application/json" id="mahara-content">' . mhRenderJson($published) . '</script>';
    $html = mhRenderReplace($html, '~</head>~', $bootstrap . "\n</head>");
    return $html;
}

function mhRenderEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mhRenderTemplate(string $name): string
{
    // Production keeps templates out of nginx's static HTML serving path.
    $local = dirname(__DIR__) . '/' . $name;
    $path = is_file($local) ? $local : __DIR__ . '/templates/' . $name . '.php';
    return (string) file_get_contents($path);
}

function mhRenderJson(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
}

function mhRenderReplace(string $html, string $pattern, string $replacement, bool $preserveTags = false): string
{
    return (string) preg_replace_callback($pattern, static fn(array $match): string => $preserveTags ? $match[1] . $replacement . $match[2] : $replacement, $html, 1);
}

function mhRenderClass(string $html, string $tag, string $class, string $inner): string
{
    return mhRenderReplace($html, '~(<' . $tag . '\b[^>]*class="[^"]*\b' . preg_quote($class, '~') . '\b[^"]*"[^>]*>)[\s\S]*?(</' . $tag . '>)~', $inner, true);
}

function mhRenderImage(string $html, string $container, string $source): string
{
    return mhRenderReplace($html, '~(<div\b[^>]*class="[^"]*\b' . preg_quote($container, '~') . '\b[^"]*"[^>]*>[\s\S]*?<img\b[^>]*\bsrc=")[^"]*(")~', mhRenderEscape($source), true);
}

function mhRenderLines(mixed $text): string
{
    return nl2br(mhRenderEscape($text), false);
}

function mhRenderHome(string $html, array $sections): string
{
    foreach (['hero', 'campaign', 'story', 'newsletter'] as $name) {
        $fields = $sections[$name];
        $pattern = '~<section\b[^>]*class="[^"]*\b' . $name . '\b[^"]*"[^>]*>[\s\S]*?</section>~';
        $html = (string) preg_replace_callback($pattern, static function (array $match) use ($name, $fields): string {
            $block = mhRenderClass($match[0], 'p', 'eyebrow', mhRenderEscape($fields['eyebrow']));
            if ($name === 'hero') {
                $block = mhRenderClass($block, 'span', 'hero-title__main', mhRenderEscape($fields['title']));
                $block = mhRenderReplace($block, '~(<span\b[^>]*class="[^"]*\bhero-title__main\b[^"]*"[^>]*\bdata-title=")[^"]*(")~', mhRenderEscape($fields['title']), true);
                $block = mhRenderClass($block, 'span', 'hero-title__sub', mhRenderEscape($fields['subtitle']));
                $block = mhRenderClass($block, 'p', 'hero__copy', mhRenderLines($fields['description']));
                $block = mhRenderImage($block, 'hero-photo--left', $fields['image']);
                $block = mhRenderImage($block, 'hero-photo--right', $fields['secondaryImage']);
                $block = mhRenderReplace($block, '~(<a\b[^>]*class="[^"]*\bbutton--primary\b[^"]*"[^>]*><span>)[\s\S]*?(</span>)~', mhRenderEscape($fields['button']), true);
            } else {
                $block = mhRenderReplace($block, '~(<h2\b[^>]*>)[\s\S]*?(</h2>)~', mhRenderEscape($fields['title']) . '<br><em>' . mhRenderEscape($fields['accent']) . '</em>', true);
                if ($name === 'campaign') {
                    $block = mhRenderReplace($block, '~(<p class="reveal">)[\s\S]*?(</p>)~', mhRenderLines($fields['description']), true);
                    $block = mhRenderImage($block, 'campaign__media', $fields['image']);
                    $block = mhRenderReplace($block, '~(<a\b[^>]*class="[^"]*\bbutton\b[^"]*"[^>]*><span>)[\s\S]*?(</span>)~', mhRenderEscape($fields['button']), true);
                } elseif ($name === 'story') {
                    $block = mhRenderClass($block, 'p', 'story__lead', mhRenderLines($fields['lead']));
                    $block = mhRenderReplace($block, '~(<p>)[\s\S]*?(</p>)~', mhRenderLines($fields['description']), true);
                    $block = mhRenderImage($block, 'story__picture--main', $fields['image']);
                    $block = mhRenderImage($block, 'story__picture--float', $fields['secondaryImage']);
                }
            }
            return $block;
        }, $html, 1);
    }
    return $html;
}

function mhRenderPrice(mixed $price): string
{
    return number_format((float) $price, 0, ',', "\u{00A0}") . ' ₽';
}

function mhRenderProductNoun(int $count): string
{
    if ($count % 100 >= 11 && $count % 100 <= 14) return 'товаров';
    return match ($count % 10) { 1 => 'товар', 2, 3, 4 => 'товара', default => 'товаров' };
}

function mhRenderSizes(array $sizes, int $selected = 0): string
{
    $buttons = [];
    foreach ($sizes as $index => $size) {
        $text = mhRenderEscape($size);
        $buttons[] = '<button type="button" data-size-choice="' . $text . '" class="' . ($index === $selected ? 'is-active' : '') . '" aria-pressed="' . ($index === $selected ? 'true' : 'false') . '" aria-label="Размер ' . $text . '">' . $text . '</button>';
    }
    return implode('', $buttons);
}

function mhRenderCard(array $product): string
{
    $p = array_map(static fn(mixed $value): mixed => is_array($value) ? $value : mhRenderEscape($value), $product);
    $title = $p['title'];
    $category = $p['category'] === 'hoodie' ? 'Худи / Вышивка' : 'Футболка / Арт';
    $sizes = mhRenderSizes($product['sizes'], count($product['sizes']) > 1 ? 1 : 0);
    $availableSizes = mhRenderEscape(implode(', ', $product['sizes']));
    $price = mhRenderPrice($product['price']);
    return <<<HTML
<article class="product-card reveal" data-product-id="{$p['id']}" data-category="{$p['category']}" data-color="{$p['color']}">
  <div class="product-card__media">
    <span class="product-card__badge">{$p['label']}</span>
    <button class="product-card__favorite js-only" type="button" data-favorite aria-pressed="false" aria-label="Добавить {$title} в избранное"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 4.7a5.6 5.6 0 0 0-7.9 0L12 5.6l-.9-.9a5.6 5.6 0 1 0-7.9 7.9l.9.9L12 21l7.9-7.5.9-.9a5.6 5.6 0 0 0 0-7.9Z"/></svg></button>
    <a class="product-card__image-link" href="{$p['url']}" aria-label="{$title} — подробнее"><img class="product-card__image" src="{$p['image']}" alt="{$title}" width="{$p['width']}" height="{$p['height']}" loading="lazy" decoding="async" /></a>
    <button class="product-card__quick js-only" type="button" data-quick-open aria-haspopup="dialog" aria-controls="quick-dialog" aria-label="Быстрый просмотр: {$title}">Быстрый просмотр</button>
  </div>
  <div class="product-card__body">
    <span class="product-card__label">{$category}</span>
    <h3><a href="{$p['url']}">{$title}</a></h3>
    <p class="product-card__subtitle">{$p['subtitle']}</p>
    <strong class="product-card__price">{$price}</strong>
    <div class="product-card__sizes js-only" role="group" aria-label="Размер {$title}">{$sizes}</div>
    <p class="product-card__available-sizes no-js-only">Размеры: {$availableSizes}</p>
    <button class="product-card__add js-only" type="button" data-add-cart aria-label="Добавить {$title} в корзину">В корзину</button>
  </div>
</article>
HTML;
}

function mhRenderColor(string $color): string
{
    return ['black' => 'Чёрный', 'green' => 'Зелёный', 'white' => 'Белый', 'slate' => 'Серо-синий', 'burgundy' => 'Бордовый'][$color] ?? $color;
}

function mhRenderProductImages(array $product): array
{
    $sources = is_array($product['images'] ?? null) && $product['images'] !== [] ? $product['images'] : [$product['image'], $product['altImage'] ?? ''];
    return array_slice(array_values(array_unique(array_filter($sources, static fn(mixed $image): bool => is_string($image) && trim($image) !== ''))), 0, 10);
}

function mhRenderProductGallery(array $product): string
{
    $images = mhRenderProductImages($product);
    $count = count($images); $slides = []; $thumbs = [];
    $width = mhRenderEscape($product['width']); $height = mhRenderEscape($product['height']);
    foreach ($images as $index => $image) {
        $source = mhRenderEscape($image);
        $alt = mhRenderEscape($product['title'] . ($index === 0 ? '' : ' — другой ракурс'));
        $loading = $index === 0 ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"';
        $slides[] = '<div class="swiper-slide' . ($index === 0 ? ' is-gallery-active' : '') . '"><img src="' . $source . '" alt="' . $alt . '" width="' . $width . '" height="' . $height . '" ' . $loading . ' decoding="async" /></div>';
        $thumbs[] = '<button class="product-gallery__thumb" type="button" data-gallery-thumb="' . $index . '" aria-label="Показать фото ' . ($index + 1) . '" aria-pressed="' . ($index === 0 ? 'true' : 'false') . '"><img src="' . $source . '" alt="" width="' . $width . '" height="' . $height . '" loading="lazy" decoding="async" /></button>';
    }
    $controls = '';
    if ($count > 1) {
        $total = str_pad((string) $count, 2, '0', STR_PAD_LEFT);
        $controls = '<div class="product-gallery__controls"><button type="button" data-gallery-prev aria-label="Предыдущее фото" disabled><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg></button><div class="product-gallery__counter" aria-live="polite" aria-atomic="true"><span data-gallery-current>01</span><span aria-hidden="true"> / </span><span data-gallery-total>' . $total . '</span></div><button type="button" data-gallery-next aria-label="Следующее фото"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m10 6 6 6-6 6"/></svg></button></div><div class="product-gallery__thumbs" role="group" aria-label="Выбор фотографии">' . implode('', $thumbs) . '</div>';
    }
    return '<div class="product-detail__gallery" data-product-gallery role="region" aria-label="Фотографии товара"' . ($count > 1 ? ' tabindex="0"' : '') . '><div class="product-gallery__viewport swiper"><div class="swiper-wrapper">' . implode('', $slides) . '</div></div>' . $controls . '</div>';
}

function mhRenderProduct(array $product): string
{
    $p = array_map(static fn(mixed $value): mixed => is_array($value) ? $value : mhRenderEscape($value), $product);
    $category = $product['category'] === 'hoodie' ? 'Худи' : 'Футболки';
    $color = mhRenderEscape(mhRenderColor($product['color']));
    $sizes = mhRenderSizes($product['sizes']);
    $availableSizes = mhRenderEscape(implode(', ', $product['sizes']));
    $price = mhRenderPrice($product['price']);
    $gallery = mhRenderProductGallery($product);
    $facts = '';
    foreach (['composition' => 'Состав', 'care' => 'Уход', 'manufacturer' => 'Изготовитель и страна производства', 'measurements' => 'Замеры и посадка'] as $key => $label) {
        if (trim((string)($product[$key] ?? '')) !== '') $facts .= '<div data-product-fact="' . $key . '"><dt>' . $label . '</dt><dd>' . nl2br(mhRenderEscape($product[$key]), false) . '</dd></div>';
    }
    return <<<HTML
<main class="product-main" id="main-content" tabindex="-1">
  <div class="container">
    <nav class="breadcrumbs" aria-label="Хлебные крошки"><a href="index.html">Главная</a><span aria-hidden="true">/</span><a href="catalog.html">Каталог</a><span aria-hidden="true">/</span><span aria-current="page">{$p['title']}</span></nav>
    <article class="product-detail" data-product-detail data-product-id="{$p['id']}" aria-labelledby="product-title">
      {$gallery}
      <div class="product-detail__copy">
        <p class="eyebrow">{$category} / MAHARA</p>
        <h1 id="product-title">{$p['title']}</h1>
        <p class="product-detail__description">{$p['description']}</p>
        <strong class="product-detail__price">{$price}</strong>
        <dl class="product-detail__facts"><div><dt>Цвет</dt><dd>{$color}</dd></div><div><dt>Размеры</dt><dd>{$availableSizes}</dd></div><div><dt>Линия</dt><dd>{$p['subtitle']}</dd></div>{$facts}</dl>
        <div class="product-card__sizes js-only" role="group" aria-label="Выбрать размер">{$sizes}</div>
        <button class="button button--primary js-only" type="button" data-add-cart aria-label="Добавить {$p['title']} в корзину">Добавить в корзину <span aria-hidden="true">↗</span></button>
        <a class="product-detail__back" href="catalog.html">Вернуться в каталог <span aria-hidden="true">→</span></a>
      </div>
    </article>
  </div>
</main>
HTML;
}

function mhRenderOrigin(string $fallback): string
{
    $configured = function_exists('mahara_config') ? (mahara_config()['origin'] ?? '') : '';
    $origin = defined('MAHARA_ORIGIN') ? constant('MAHARA_ORIGIN') : (getenv('MAHARA_ORIGIN') ?: ($configured ?: (getenv('APP_ORIGIN') ?: $fallback)));
    $parts = parse_url((string) $origin);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || !in_array($parts['path'] ?? '', ['', '/'], true)) $origin = $fallback;
    return rtrim((string) $origin, '/') . '/';
}

function mhRenderDescription(string $text): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    preg_match('/^.{0,180}/us', $text, $match);
    return $match[0] ?? $text;
}

function mhRenderHead(string $route, array $sections, array $products, ?array $product, array $config, string $origin): string
{
    $url = static fn(string $file): string => $origin . ($file === 'index.html' ? '' : $file);
    $brand = (string) ($config['brand'] ?? 'MAHARA');
    $organization = ['@type' => 'Organization', '@id' => $origin . '#organization', 'name' => $brand, 'url' => $origin, 'logo' => $url('assets/images/mahara-emblem.webp')];
    $website = ['@type' => 'WebSite', '@id' => $origin . '#website', 'name' => $brand, 'url' => $origin, 'inLanguage' => 'ru-RU', 'publisher' => ['@id' => $organization['@id']]];
    $page = ['@type' => 'WebPage', '@id' => $url($route) . '#page', 'url' => $url($route), 'inLanguage' => 'ru-RU', 'isPartOf' => ['@id' => $website['@id']]];
    $image = 'assets/images/social-cover.jpg';
    $width = 1200; $height = 630;
    $crumbs = [['Главная', 'index.html']];
    if ($product !== null) {
        $title = $product['title'] . ' — ' . ($product['category'] === 'hoodie' ? 'худи с вышивкой' : 'футболка из хлопка') . ' | ' . $brand;
        $description = mhRenderDescription($product['description'] . '; размеры ' . implode(', ', $product['sizes']));
        $photos = mhRenderProductImages($product);
        $image = $photos[0] ?? $product['image']; $width = max(1, (int) $product['width']); $height = max(1, (int) $product['height']);
        $schema = ['@type' => 'Product', '@id' => $url($route) . '#product', 'name' => $product['title'], 'url' => $url($route), 'description' => $product['description'], 'image' => array_map($url, $photos), 'brand' => ['@type' => 'Brand', 'name' => $brand], 'category' => $product['category'] === 'hoodie' ? 'Худи' : 'Футболки', 'color' => mhRenderColor($product['color']), 'size' => $product['sizes']];
        $page['name'] = $product['title']; $page['mainEntity'] = ['@id' => $schema['@id']];
        $graph = [$organization, $website, $schema, $page];
        $crumbs[] = ['Каталог', 'catalog.html']; $crumbs[] = [$product['title'], $route];
    } elseif ($route === 'catalog.html') {
        $title = 'Каталог ' . $brand . ' — худи с вышивкой и футболки с принтами';
        $description = 'Каталог одежды ' . $brand . ': худи с архитектурной вышивкой, футболки с арт-принтами, цвета и размеры моделей текущего дропа';
        $page['@type'] = 'CollectionPage'; $page['name'] = 'Каталог одежды ' . $brand;
        $items = [];
        foreach ($products as $index => $item) $items[] = ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $item['title'], 'url' => $url($item['url'])];
        $page['mainEntity'] = ['@type' => 'ItemList', 'numberOfItems' => count($products), 'itemListElement' => $items];
        $graph = [$organization, $website, $page]; $crumbs[] = ['Каталог', 'catalog.html'];
    } else {
        $title = $sections['hero']['title'] . ' — российский бренд одежды | Худи и футболки';
        $description = mhRenderDescription($sections['hero']['description']);
        $page['name'] = $sections['hero']['title']; $page['about'] = ['@id' => $organization['@id']];
        $graph = [$organization, $website, $page];
    }
    if (count($crumbs) > 1) {
        $items = [];
        foreach ($crumbs as $index => [$name, $file]) $items[] = ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $name, 'item' => $url($file)];
        $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }
    $eTitle = mhRenderEscape($title); $eDescription = mhRenderEscape($description); $eUrl = mhRenderEscape($url($route)); $eImage = mhRenderEscape($url($image));
    $eBrand = mhRenderEscape($brand); $eLocale = mhRenderEscape($config['locale'] ?? 'ru_RU');
    $json = mhRenderJson(['@context' => 'https://schema.org', '@graph' => $graph]);
    $galleryStyles = $product === null ? '' : "\n  <link rel=\"stylesheet\" href=\"/assets/vendor/swiper/swiper-bundle.min.css?v=14.3.0\" />\n  <link rel=\"stylesheet\" href=\"/product-gallery.css?v=20261015-1\" />";
    $galleryScripts = $product === null ? '' : "\n  <script defer src=\"/assets/vendor/swiper/swiper-bundle.min.js?v=14.3.0\"></script>\n  <script defer src=\"/product-gallery.js?v=20261015-2\"></script>";
    $cmsVersion = '20261015-2';
    return <<<HTML
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="theme-color" content="#050505" />
  <title>{$eTitle}</title>
  <meta name="description" content="{$eDescription}" />
  <meta name="robots" content="index, follow, max-image-preview:large" />
  <link rel="canonical" href="{$eUrl}" />
  <meta property="og:locale" content="{$eLocale}" />
  <meta property="og:type" content="website" />
  <meta property="og:site_name" content="{$eBrand}" />
  <meta property="og:title" content="{$eTitle}" />
  <meta property="og:description" content="{$eDescription}" />
  <meta property="og:url" content="{$eUrl}" />
  <meta property="og:image" content="{$eImage}" />
  <meta property="og:image:width" content="{$width}" />
  <meta property="og:image:height" content="{$height}" />
  <meta property="og:image:alt" content="{$eTitle}" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="{$eTitle}" />
  <meta name="twitter:description" content="{$eDescription}" />
  <meta name="twitter:image" content="{$eImage}" />
  <meta name="twitter:image:alt" content="{$eTitle}" />
  <link rel="icon" href="assets/icons/favicon.svg" type="image/svg+xml" />
  <link rel="stylesheet" href="styles.css?v=20261011-2" />{$galleryStyles}
  <script type="application/ld+json">{$json}</script>
  <script defer src="catalog-data.js?v=20261011-2"></script>{$galleryScripts}
  <script defer src="cms-data.js?v={$cmsVersion}"></script>
  <script defer src="script.js?v=20261012-1"></script>
</head>
HTML;
}
