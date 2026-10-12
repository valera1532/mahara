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
verify(value($xpath, '//div[' . hasClass('product-detail__gallery') . ']/img[1]/@src') === $product['image'], 'Dynamic product image.');
verify(value($xpath, '//div[' . hasClass('product-detail__gallery') . ']/img[2]/@src') === $product['altImage'], 'Dynamic product secondary image.');
verify((int) $xpath->evaluate('count(//*[@data-size-choice])') === 1, 'Dynamic product sizes are current.');
$schema = json_decode(value($xpath, '//script[@type="application/ld+json"]'), true, 512, JSON_THROW_ON_ERROR);
$schemaProducts = array_values(array_filter($schema['@graph'], static fn(array $node): bool => $node['@type'] === 'Product'));
verify($schemaProducts[0]['name'] === $product['title'] && $schemaProducts[0]['description'] === $product['description'], 'Product JSON-LD is current and safe.');

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
