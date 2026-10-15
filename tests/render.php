<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/render.php';

$checks = 0;
function verify(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function document(string $html): DOMXPath
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($document);
}
function value(DOMXPath $xpath, string $query): string
{
    return (string) $xpath->evaluate('string(' . $query . ')');
}
function hasClass(string $name): string
{
    return 'contains(concat(" ", normalize-space(@class), " "), " ' . $name . ' ")';
}

$content = json_decode((string) file_get_contents(dirname(__DIR__) . '/site-defaults.json'), true, 512, JSON_THROW_ON_ERROR);
$content['revision'] = 7;
foreach ($content['sections'] as $name => &$fields) {
    foreach ($fields as $key => &$field) {
        $field = str_contains(strtolower($key), 'image') ? "assets/uploads/$name-$key.webp" : "$name $key <>&\"'\nSecond line \$1";
    }
    unset($field);
}
unset($fields);
$product = $content['products'][0];
$product['id'] = 'new-product'; $product['url'] = 'product-new-product.html';
$product['title'] = 'Новая модель " </script><script>alert(1)</script> & $1';
$product['description'] = 'Публикация новой модели <>& " описание';
$product['image'] = 'assets/uploads/new-model.webp';
$product['altImage'] = 'assets/uploads/new-model-back.webp';
$product['price'] = 12345;
$product['sizes'] = ['XL'];
$content['products'] = [$product];

$home = renderPage('/', $content);
verify(is_string($home), 'Home must render.');
$xpath = document($home);
$hero = $content['sections']['hero'];
verify(value($xpath, '//span[' . hasClass('hero-title__main') . ']') === $hero['title'], 'SSR hero title must be current and escaped.');
verify(value($xpath, '//span[' . hasClass('hero-title__main') . ']/@data-title') === $hero['title'], 'Hero data-title must match current title.');
verify(value($xpath, '//span[' . hasClass('hero-title__sub') . ']') === $hero['subtitle'], 'SSR hero subtitle.');
verify(value($xpath, '//p[' . hasClass('hero__copy') . ']') === $hero['description'], 'SSR multiline hero description.');
verify(value($xpath, '//div[' . hasClass('hero-photo--left') . ']//img/@src') === $hero['image'], 'SSR hero image.');
verify(value($xpath, '//div[' . hasClass('hero-photo--right') . ']//img/@src') === $hero['secondaryImage'], 'SSR secondary hero image.');
verify(value($xpath, '//div[' . hasClass('hero__actions') . ']//a[' . hasClass('button--primary') . ']/span') === $hero['button'], 'SSR hero button.');
foreach (['campaign', 'story', 'newsletter'] as $name) {
    $section = '//section[' . hasClass($name) . ']';
    $fields = $content['sections'][$name];
    verify(value($xpath, $section . '//p[' . hasClass('eyebrow') . ']') === $fields['eyebrow'], "SSR $name eyebrow.");
    verify(value($xpath, $section . '//h2/em') === $fields['accent'], "SSR $name heading accent.");
    verify(value($xpath, $section . '//h2/text()[1]') === $fields['title'], "SSR $name heading title.");
}
verify(value($xpath, '//div[' . hasClass('campaign__content') . ']/p[not(' . hasClass('eyebrow') . ')]') === $content['sections']['campaign']['description'], 'SSR campaign description.');
verify(value($xpath, '//div[' . hasClass('campaign__media') . ']//img/@src') === $content['sections']['campaign']['image'], 'SSR campaign image.');
verify(value($xpath, '//div[' . hasClass('campaign__content') . ']//a/span') === $content['sections']['campaign']['button'], 'SSR campaign button.');
verify(value($xpath, '//p[' . hasClass('story__lead') . ']') === $content['sections']['story']['lead'], 'SSR story lead.');
verify(value($xpath, '//div[' . hasClass('story__copy') . ']/p[not(@class)]') === $content['sections']['story']['description'], 'SSR story description.');
verify(value($xpath, '//div[' . hasClass('story__picture--main') . ']//img/@src') === $content['sections']['story']['image'], 'SSR story image.');
verify(value($xpath, '//div[' . hasClass('story__picture--float') . ']//img/@src') === $content['sections']['story']['secondaryImage'], 'SSR story secondary image.');
verify((int) $xpath->evaluate('count(//article[@data-product-id])') === 1, 'Featured cards use published products.');
$bootstrap = json_decode(value($xpath, '//script[@id="mahara-content"]'), true, 512, JSON_THROW_ON_ERROR);
verify($bootstrap === $content, 'Bootstrap JSON preserves the published snapshot.');

$catalog = renderPage('/catalog.html?q=test', $content);
$xpath = document($catalog);
verify((int) $xpath->evaluate('count(//article[@data-product-id])') === 1, 'Catalog contains current products only.');
verify(value($xpath, '//article[@data-product-id]/@data-product-id') === 'new-product', 'New product card has binding id.');
verify(value($xpath, '//article[@data-product-id]//h3/a/@href') === 'product-new-product.html', 'New product has crawlable URL.');
verify(value($xpath, '//span[@data-results-count]') === '1 товар', 'Catalog count is current.');
verify(str_contains(value($xpath, '//strong[' . hasClass('product-card__price') . ']'), '12'), 'Catalog contains current price.');
verify(renderPage('/product-hoodie-black.html', $content) === null, 'Deleted product must return null even if a static file remains.');
verify(renderPage('/product-unknown.html', $content) === null, 'Missing product returns null.');
verify(renderPage('/../index.html', $content) === null, 'Traversal route rejected.');
verify(renderPage('/admin.html', $content) === null, 'Admin is outside the storefront renderer.');

$detail = renderPage('/product-new-product.html', $content);
$xpath = document($detail);
verify(value($xpath, '//h1[@id="product-title"]') === $product['title'], 'Dynamic product title is current.');
verify(value($xpath, '//p[' . hasClass('product-detail__description') . ']') === $product['description'], 'Dynamic product description is current.');
verify(value($xpath, '//link[@rel="canonical"]/@href') === 'https://maharussia.ru/product-new-product.html', 'New product canonical is correct.');
verify(str_starts_with(value($xpath, '//title'), $product['title']), 'Dynamic title metadata.');
verify((int) $xpath->evaluate('count(//script[not(@src) and not(@type)])') === 0, 'Untrusted text cannot inject scripts.');
$gallery = '//*[@data-product-gallery]';
$slides = $gallery . '/div[' . hasClass('product-gallery__viewport') . ' and ' . hasClass('swiper') . ']/div[' . hasClass('swiper-wrapper') . ']/div[' . hasClass('swiper-slide') . ']';
verify((int) $xpath->evaluate('count(' . $gallery . ')') === 1 && value($xpath, $gallery . '/@role') === 'region' && value($xpath, $gallery . '/@aria-label') === 'Фотографии товара', 'Gallery is a single named region.');
verify((int) $xpath->evaluate('count(' . $slides . ')') === 2, 'Different published images produce two carousel slides.');
verify(value($xpath, '(' . $slides . ')[1]/img/@src') === $product['image'], 'Dynamic product image.');
verify(value($xpath, '(' . $slides . ')[2]/img/@src') === $product['altImage'], 'Dynamic product secondary image.');
verify(value($xpath, '(' . $slides . ')[1]/img/@alt') === $product['title'], 'Gallery image text remains escaped.');
verify(value($xpath, '(' . $slides . ')[1]/img/@loading') === 'eager' && value($xpath, '(' . $slides . ')[1]/img/@fetchpriority') === 'high', 'First gallery image has loading priority.');
verify(value($xpath, '(' . $slides . ')[2]/img/@loading') === 'lazy' && value($xpath, '(' . $slides . ')[1]/@class') === 'swiper-slide is-gallery-active', 'Later images are lazy and the SSR fallback selects the first slide.');
verify((int) $xpath->evaluate('count(' . $gallery . '//*[@data-gallery-prev and @disabled])') === 1 && (int) $xpath->evaluate('count(' . $gallery . '//*[@data-gallery-next and not(@disabled)])') === 1, 'Navigation starts at the first non-looping slide.');
verify(value($xpath, $gallery . '//*[@data-gallery-current]') === '01' && value($xpath, $gallery . '//*[@data-gallery-total]') === '02', 'Gallery counter starts with padded image numbers.');
verify((int) $xpath->evaluate('count(' . $gallery . '//button[@data-gallery-thumb])') === 2 && value($xpath, $gallery . '//button[@data-gallery-thumb="0"]/@aria-pressed') === 'true' && value($xpath, $gallery . '//button[@data-gallery-thumb="1"]/@aria-pressed') === 'false', 'Thumbnail selection matches the first slide.');
verify(value($xpath, $gallery . '//button[@data-gallery-thumb="1"]/@aria-label') === 'Показать фото 2' && (int) $xpath->evaluate('count(' . $gallery . '//button[@data-gallery-thumb]/img[@alt=""])') === 2, 'Thumbnail controls have accessible labels and decorative images.');
foreach (['/assets/vendor/swiper/swiper-bundle.min.css?v=14.3.0', '/product-gallery.css?v=20261015-1'] as $asset) verify((int) $xpath->evaluate('count(//head/link[@rel="stylesheet" and @href="' . $asset . '"])') === 1, 'Product gallery stylesheet is loaded once.');
foreach (['/assets/vendor/swiper/swiper-bundle.min.js?v=14.3.0', '/product-gallery.js?v=20261015-2', 'cms-data.js?v=20261015-2'] as $asset) verify((int) $xpath->evaluate('count(//head/script[@defer and @src="' . $asset . '"])') === 1, 'Product gallery scripts use the matching cache version.');
verify(strpos($detail, '/swiper-bundle.min.js?') < strpos($detail, '/product-gallery.js?') && strpos($detail, '/product-gallery.js?') < strpos($detail, 'cms-data.js?'), 'Gallery controller loads after its vendor and before the CMS repaint.');
verify(!str_contains($home, 'swiper-bundle') && !str_contains($catalog, 'swiper-bundle'), 'Non-product pages do not load gallery dependencies.');
verify(str_contains($home, 'cms-data.js?v=20261015-2') && str_contains($catalog, 'cms-data.js?v=20261015-2'), 'All public SSR pages load the multi-photo CMS normalizer.');
verify((int) $xpath->evaluate('count(//*[@data-size-choice])') === 1, 'Dynamic product sizes are current.');
$schema = json_decode(value($xpath, '//script[@type="application/ld+json"]'), true, 512, JSON_THROW_ON_ERROR);
$schemaProducts = array_values(array_filter($schema['@graph'], static fn(array $node): bool => $node['@type'] === 'Product'));
verify($schemaProducts[0]['name'] === $product['title'] && $schemaProducts[0]['description'] === $product['description'], 'Product JSON-LD is current and safe.');

$singleContent = $content; $singleContent['products'][0]['altImage'] = $product['image'];
$single = document(renderPage('/product-new-product.html', $singleContent));
verify((int) $single->evaluate('count(' . $slides . ')') === 1, 'Repeated image URLs produce one slide.');
verify((int) $single->evaluate('count(' . $gallery . '//*[@data-gallery-prev or @data-gallery-next or @data-gallery-current or @data-gallery-total or @data-gallery-thumb])') === 0, 'A single image has no misleading navigation, counter or thumbnails.');
verify(value($single, '(' . $slides . ')[1]/img/@src') === $product['image'] && value($single, '(' . $slides . ')[1]/img/@loading') === 'eager', 'A single image remains visible and eager without JavaScript.');
$missingAlt = $product; unset($missingAlt['altImage']);
verify((int) document(mhRenderProductGallery($missingAlt))->evaluate('count(//div[' . hasClass('swiper-slide') . '])') === 1, 'An absent alternate image never creates an empty source.');

$manyContent = $content;
$photos = array_map(static fn(int $index): string => 'assets/uploads/gallery-' . $index . '.webp', range(1, 10));
$manyContent['products'][0]['images'] = $photos;
$manyHtml = renderPage('/product-new-product.html', $manyContent); $many = document($manyHtml);
$renderedPhotos = []; foreach ($many->query($slides . '/img') as $image) $renderedPhotos[] = $image->getAttribute('src');
verify($renderedPhotos === $photos && (int) $many->evaluate('count(' . $gallery . '//button[@data-gallery-thumb])') === 10, 'All ten canonical images appear as ordered slides and thumbnails.');
verify(value($many, $gallery . '//*[@data-gallery-current]') === '01' && value($many, $gallery . '//*[@data-gallery-total]') === '10', 'Ten-image navigation has the correct total.');
verify((int) $many->evaluate('count(' . $slides . '/img[@loading="eager" and @fetchpriority="high"])') === 1 && (int) $many->evaluate('count(' . $slides . '/img[@loading="lazy"])') === 9, 'Only the first of ten main images is eager.');
$manySchema = json_decode(value($many, '//script[@type="application/ld+json"]'), true, 512, JSON_THROW_ON_ERROR);
$manySchemaProduct = array_values(array_filter($manySchema['@graph'], static fn(array $node): bool => $node['@type'] === 'Product'))[0];
verify($manySchemaProduct['image'] === array_map(static fn(string $photo): string => 'https://maharussia.ru/' . $photo, $photos), 'Product JSON-LD includes all unique canonical photos.');
verify(value($many, '//meta[@property="og:image"]/@content') === 'https://maharussia.ru/' . $photos[0], 'The primary social image follows canonical photo ordering.');
$canonicalSingle = $product; $canonicalSingle['images'] = [$photos[0], $photos[0]];
verify((int) document(mhRenderProductGallery($canonicalSingle))->evaluate('count(//div[' . hasClass('swiper-slide') . '])') === 1, 'Renderer also deduplicates canonical photo URLs.');

$defaults = json_decode((string) file_get_contents(dirname(__DIR__) . '/site-defaults.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($defaults['products'] as $staticProduct) {
    $staticHtml = (string) file_get_contents(dirname(__DIR__) . '/' . $staticProduct['url']);
    $static = document($staticHtml); $expectedImages = array_values(array_unique([$staticProduct['image'], $staticProduct['altImage']]));
    $actualImages = [];
    foreach ($static->query($slides . '/img') as $image) $actualImages[] = $image->getAttribute('src');
    verify($actualImages === $expectedImages, 'Static gallery uses unique images in published order: ' . $staticProduct['url']);
    verify((int) $static->evaluate('count(' . $gallery . '//button[@data-gallery-thumb])') === (count($expectedImages) > 1 ? count($expectedImages) : 0), 'Static single/multiple image controls match the slide count.');
    verify(value($static, '(' . $slides . ')[1]/img/@loading') === 'eager' && value($static, '(' . $slides . ')[1]/img/@fetchpriority') === 'high', 'Static first image keeps loading priority.');
    verify(str_contains($staticHtml, '/swiper-bundle.min.css?v=14.3.0') && str_contains($staticHtml, '/product-gallery.css?v=20261015-1') && str_contains($staticHtml, '/product-gallery.js?v=20261015-2') && str_contains($staticHtml, 'cms-data.js?v=20261015-2') && strpos($staticHtml, '/swiper-bundle.min.js?') < strpos($staticHtml, '/product-gallery.js?') && strpos($staticHtml, '/product-gallery.js?') < strpos($staticHtml, 'cms-data.js?'), 'Static gallery dependencies load in the same order as SSR.');
}

$sitemap = renderPage('/sitemap.xml', $content);
verify(str_contains($sitemap, 'https://maharussia.ru/product-new-product.html'), 'New product appears in sitemap.');
verify(!str_contains($sitemap, 'product-hoodie-black.html'), 'Deleted product is removed from sitemap.');
verify(str_contains(renderPage('/robots.txt', $content), 'Disallow: /admin'), 'Robots excludes administration.');
$content['products'] = [];
$xpath = document(renderPage('/catalog.html', $content));
verify((int) $xpath->evaluate('count(//article[@data-product-id])') === 0, 'Empty published catalog must stay empty.');
verify(!str_contains(renderPage('/sitemap.xml', $content), 'product-'), 'Empty sitemap has no product routes.');
putenv('MAHARA_ORIGIN=https://shop.example.test');
verify(str_contains(renderPage('/sitemap.xml', $content), 'https://shop.example.test/catalog.html'), 'Origin may be configured.');
putenv('MAHARA_ORIGIN=https://user@shop.example.test');
verify(str_contains(renderPage('/sitemap.xml', $content), 'https://maharussia.ru/catalog.html'), 'Origin credentials rejected.');
putenv('MAHARA_ORIGIN');
putenv('APP_ORIGIN=https://configured.example.test');
verify(str_contains(renderPage('/sitemap.xml', $content), 'https://configured.example.test/catalog.html'), 'Backend APP_ORIGIN also configures public canonical URLs.');
putenv('APP_ORIGIN');
echo "PASS: $checks storefront rendering checks\n";
