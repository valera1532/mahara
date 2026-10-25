<?php
declare(strict_types=1);

// Pure validation fixtures: no database, private configuration or HTTP transport.
define('MAHARA_LIBRARY_ONLY', true);
$GLOBALS['mahara_app_test_config'] = [];
require_once dirname(__DIR__) . '/server/app.php';
$fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/site-defaults.json'), true, 64, JSON_THROW_ON_ERROR);
$base = $fixture['products'][0]; unset($base['images']);
$checks = 0;
function images_verify(bool $condition, string $message): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function images_validate(array $product): array {
    global $fixture;
    $input = $fixture; $input['products'] = [$product];
    return mahara_validate_content($input, false)['products'][0];
}
function images_reject(array $product, bool $limit = false): void {
    try { images_validate($product); }
    catch (MaharaHttpError $error) {
        images_verify($error->status === 400 && (!$limit || str_contains($error->getMessage(), '10')), 'Invalid product photos are rejected with a bounded validation error.'); return;
    }
    throw new RuntimeException('Invalid product photos were accepted.');
}

$legacy = images_validate($base);
images_verify($legacy['images'] === array_values(array_unique([$base['image'], $base['altImage']])), 'Legacy image fields derive an ordered unique gallery.');
images_verify($legacy['image'] === $base['image'] && $legacy['altImage'] === $base['altImage'], 'Legacy two-photo aliases retain their meaning.');
$single = $base; $single['altImage'] = $single['image']; $single = images_validate($single);
images_verify($single['images'] === [$base['image']] && $single['altImage'] === $single['image'], 'A repeated legacy photo remains one image.');

$photos = array_map(static fn(int $index): string => 'assets/uploads/photo-' . $index . '.webp', range(1, 10));
$many = $base; $many['images'] = $photos;
$validated = images_validate($many);
images_verify($validated['images'] === $photos && $validated['image'] === $photos[0] && $validated['altImage'] === $photos[1], 'Ten photos are preserved and canonicalize conflicting but valid aliases.');
images_verify($many['image'] === $base['image'] && $many['altImage'] === $base['altImage'], 'Normalization does not mutate the incoming request.');
images_verify($validated['price'] === $base['price'] && $validated['sizes'] === $base['sizes'] && $validated['id'] === $base['id'], 'Photo normalization does not change product identity, price or sizes.');
images_verify(images_validate($validated) === $validated, 'Canonical image data survives a save roundtrip.');
$one = $many; $one['images'] = [$photos[3]]; $one = images_validate($one);
images_verify($one['image'] === $photos[3] && $one['altImage'] === $photos[3] && $one['images'] === [$photos[3]], 'A single canonical photo sets both legacy aliases.');
$duplicate = $many; $duplicate['images'] = [$photos[2], $photos[1], $photos[2], $photos[0]]; $duplicate = images_validate($duplicate);
images_verify($duplicate['images'] === [$photos[2], $photos[1], $photos[0]], 'Duplicate valid photos are removed without reordering.');
$reordered = $many; $reordered['images'] = array_reverse($photos); $reordered = images_validate($reordered);
images_verify($reordered['images'] === array_reverse($photos) && $reordered['image'] === $photos[9] && $reordered['altImage'] === $photos[8], 'Reordering updates aliases to match the first two photos.');

foreach ([[], null, false, 'assets/images/photo.webp', ['first' => $photos[0]], [1], [[]], [''], ['data:image/png;base64,AAAA'], ['https://attacker.example/a.webp'], ['/assets/images/a.webp'], ['assets/images/../a.webp'], ['assets/uploads/a.svg'], ['assets/uploads/a.webp?x=1'], ["assets/uploads/a\0.webp"]] as $invalid) {
    $product = $base; $product['images'] = $invalid; images_reject($product);
}
foreach ([array_merge($photos, ['assets/uploads/eleven.webp']), array_fill(0, 11, $photos[0])] as $tooMany) {
    $product = $base; $product['images'] = $tooMany; images_reject($product, true);
}
$unsafe = $many; $unsafe['image'] = 'data:image/png;base64,AAAA'; images_reject($unsafe);
$unsafe = $many; $unsafe['altImage'] = 'https://attacker.example/a.webp'; images_reject($unsafe);
images_verify(!array_key_exists('images', $base), 'Legacy source data is not migrated or rewritten by validation.');

function categories_reject(array $content, bool $revision = false, bool $refresh = false): void {
    try { mahara_validate_content($content, $revision); }
    catch (MaharaHttpError $error) {
        images_verify($error->status === 400 && (!$refresh || str_contains($error->getMessage(), 'Обновите страницу')), 'Invalid category photos or an outdated editor are rejected before publication.'); return;
    }
    throw new RuntimeException('Invalid category photos were accepted.');
}

$categoryDefaults = [
    'hoodieImage' => 'assets/images/hero-man.webp',
    'hoodieSecondaryImage' => 'assets/images/green-hoodie-back.webp',
    'teeImage' => 'assets/images/slate-tee.webp',
    'teeSecondaryImage' => 'assets/images/white-last-supper.webp',
];
images_verify($fixture['sections']['categories'] === $categoryDefaults, 'Default category photos preserve the existing homepage images.');
$legacyContent = $fixture; unset($legacyContent['sections']['categories']);
$legacySnapshot = $legacyContent;
$legacyValidated = mahara_validate_content($legacyContent, false);
images_verify(!array_key_exists('categories', $legacyValidated['sections']) && $legacyContent === $legacySnapshot, 'Legacy content is accepted without inserting categories or mutating its source.');

$updatedContent = $fixture; $updatedContent['revision'] = 7;
foreach (array_keys($categoryDefaults) as $index => $field) $updatedContent['sections']['categories'][$field] = $photos[$index];
$savedContent = mahara_validate_content($updatedContent);
images_verify($savedContent['sections']['categories'] === $updatedContent['sections']['categories'], 'A revisioned save payload preserves all four independently selected category photos.');
images_verify($savedContent['products'] === $legacyValidated['products'], 'Editing category photos leaves every product and its photo gallery unchanged.');
images_verify(mahara_validate_content($savedContent + ['revision' => 8]) === $savedContent, 'Category photos survive a repeated save without normalization drift.');
$upgradedContent = $legacyContent; $upgradedContent['revision'] = 7; $upgradedContent['sections']['categories'] = $categoryDefaults;
images_verify(mahara_validate_content($upgradedContent)['sections']['categories'] === $categoryDefaults, 'A current editor can add defaults to legacy content and save it.');
$oldEditor = $savedContent + ['revision' => 8]; unset($oldEditor['sections']['categories']);
categories_reject($oldEditor, true, true);
images_verify($savedContent['sections']['categories'] === $updatedContent['sections']['categories'], 'Rejected old-editor data cannot replace the configured category photos.');

foreach (array_keys($categoryDefaults) as $field) {
    $missing = $fixture; unset($missing['sections']['categories'][$field]); categories_reject($missing);
    foreach ([null, 1, [], '', 'https://attacker.example/photo.webp', '//attacker.example/photo.webp', 'data:image/png;base64,AAAA', '/assets/images/photo.webp', 'assets/images/../photo.webp', 'assets/uploads/photo.svg', 'assets/uploads/photo.webp?x=1', "assets/uploads/photo\0.webp"] as $unsafe) {
        $invalid = $fixture; $invalid['sections']['categories'][$field] = $unsafe; categories_reject($invalid);
    }
}
foreach ([null, [], 'categories', $categoryDefaults + ['extraImage' => $photos[0]]] as $invalidCategories) {
    $invalid = $fixture; $invalid['sections']['categories'] = $invalidCategories; categories_reject($invalid);
}
$extraSection = $legacyContent; $extraSection['sections']['unknown'] = []; categories_reject($extraSection);
echo 'PASS: ' . $checks . " product and category image validation checks\n";
