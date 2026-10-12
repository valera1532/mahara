<?php
declare(strict_types=1);

// Pure launch-readiness checks. No application config, private files, real stock,
// network, payments, or mail are loaded. SQLite exists only in memory.
$launchConfig = ['merchant' => []];
function mahara_config(): array { return $GLOBALS['launchConfig']; }
require_once dirname(__DIR__) . '/server/merchant.php';

$checks = 0;
function launchVerify(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function launchDocument(string $html): DOMXPath {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    return new DOMXPath($document);
}
function launchSet(array &$value, string $path, mixed $text): void {
    $keys = explode('.', $path); $last = array_pop($keys); $target =& $value;
    foreach ($keys as $key) { if (!isset($target[$key])) $target[$key] = []; $target =& $target[$key]; }
    $target[$last] = $text;
}

launchVerify(mahara_merchant_legal_status()['ready'] === false, 'Default documents must not enable checkout.');
$missing = array_column(mahara_merchant_missing(), 'field');
launchVerify(in_array('identity.legalName', $missing, true) && in_array('identity.registrationNumber', $missing, true), 'Readiness identifies missing seller facts.');
foreach (['privacy', 'terms', 'delivery', 'returns', 'contacts'] as $key) {
    $html = mahara_merchant_render('/' . $key . '.html');
    launchVerify(is_string($html), $key . ': dynamic page exists without a static file.');
    $xpath = launchDocument($html);
    launchVerify(str_contains((string)$xpath->evaluate('string(//meta[@name="robots"]/@content)'), 'noindex'), $key . ': draft is not indexable.');
    launchVerify((int)$xpath->evaluate('count(//aside[contains(@class,"legal-draft")])') === 0, $key . ': internal draft notice is not shown.');
    launchVerify((int)$xpath->evaluate('count(//h1)') === 1, $key . ': one heading.');
    launchVerify((int)$xpath->evaluate('count(//a[contains(@href,".html")])') >= 6, $key . ': document navigation is available.');
}
launchVerify(mahara_merchant_render('/unknown-document.html') === null, 'Unknown pages are not fabricated.');

$complete = ['published' => true, 'legalVersion' => 'audit-test-v1'];
foreach (mahara_legal_requirements() as $path => $requirement) {
    if (in_array($path, ['published', 'legalVersion'], true)) continue;
    launchSet($complete, $path, 'Тестовое значение: ' . $path);
}
$launchConfig['merchant'] = $complete;
launchVerify(mahara_merchant_legal_status()['ready'] === true, 'Complete owner-confirmed documents are ready.');
launchVerify(mahara_merchant_missing() === [], 'Complete confirmed documents have no missing required facts.');
foreach (mahara_legal_requirements() as $path => $requirement) {
    if (!$requirement['required'] || in_array($path, ['privacy.operatorAddress', 'privacy.contact'], true)) continue;
    $changed = $complete;
    launchSet($changed, $path, $path === 'published' ? false : '');
    $documents = mahara_legal_content($changed);
    foreach ($requirement['documents'] as $key) launchVerify($documents[$key]['draft'] === true && in_array($path, $documents[$key]['missing'], true), $path . ': incomplete document remains a draft.');
}
$fallback = $complete;
$fallback['identity']['email'] = '';
$fallback['privacy']['operatorAddress'] = '';
$fallback['privacy']['contact'] = '';
launchVerify(mahara_legal_value($fallback, 'identity.email') === '', 'A seller phone does not replace the mandatory claims email.');
launchVerify(mahara_legal_value($fallback, 'privacy.operatorAddress') === $complete['identity']['address'], 'Privacy operator address uses the confirmed seller address.');
launchVerify(mahara_legal_value($fallback, 'privacy.contact') === '', 'Missing seller email does not silently turn the privacy contact into a phone.');
$launchConfig['merchant'] = $fallback;
launchVerify(mahara_merchant_legal_status()['ready'] === false, 'Phone-only seller settings cannot publish legal documents or enable checkout.');
foreach (['privacy', 'terms', 'delivery', 'returns'] as $key) {
    $phoneOnly = mahara_legal_content($fallback)[$key];
    launchVerify($phoneOnly['draft'] && in_array('identity.email', $phoneOnly['missing'], true), $key . ': missing claims email keeps phone-only settings in draft.');
}
$emailOnly = $complete;
$emailOnly['identity']['phone'] = '';
$emailOnly['privacy']['contact'] = '';
$launchConfig['merchant'] = $emailOnly;
launchVerify(mahara_merchant_legal_status()['ready'] === true, 'Confirmed seller email and claims channel are sufficient without a public phone.');
launchVerify(mahara_legal_value($emailOnly, 'privacy.contact') === $complete['identity']['email'], 'Privacy contact can use the confirmed seller email.');
$emailOnly['commerce']['claimsChannel'] = '';
$launchConfig['merchant'] = $emailOnly;
launchVerify(mahara_merchant_legal_status()['ready'] === false, 'An email alone does not replace the documented claims procedure.');
$launchConfig['merchant'] = $complete;
$launchConfig['merchant']['identity']['legalName'] = 'Тестовый ИП <script id="launch-xss">alert(1)</script> & "цитата"';
$html = mahara_merchant_render('/terms.html');
$xpath = launchDocument($html);
launchVerify((int)$xpath->evaluate('count(//script[@id="launch-xss"])') === 0 && str_contains($html, '&lt;script'), 'Configured merchant text is escaped.');
launchVerify(!str_contains((string)$xpath->evaluate('string(//meta[@name="robots"]/@content)'), 'noindex'), 'Confirmed legal pages become indexable.');
$launchConfig['merchant']['published'] = false;
launchVerify(mahara_merchant_legal_status()['ready'] === false, 'Removing owner publication confirmation closes legal readiness.');

$launchDB = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$launchProducts = [
    ['id' => 'audit-a', 'sizes' => ['S', 'M', 'L', 'XL']],
    ['id' => 'audit-b', 'sizes' => ['M']],
];
function mahara_db(): PDO { return $GLOBALS['launchDB']; }
function mahara_query(string $sql, array $params = []): PDOStatement {
    $statement = mahara_db()->prepare($sql); $statement->execute($params); return $statement;
}
function mahara_content(): array { return ['products' => $GLOBALS['launchProducts']]; }
require_once dirname(__DIR__) . '/server/availability.php';
$availability = mahara_catalog_availability();
launchVerify($availability['products'][0]['variants'][0] === ['size' => 'S', 'status' => 'untracked', 'maxQty' => 0], 'Fresh catalog does not invent inventory.');
launchVerify((int)$launchDB->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn() === 0, 'Availability does not initialise inventory or order tables.');
$launchDB->exec('CREATE TABLE inventory_balances(product_id TEXT,size TEXT,quantity INTEGER,initialized INTEGER); CREATE TABLE checkout_reservations(product_id TEXT,size TEXT,quantity INTEGER);');
$launchDB->exec("INSERT INTO inventory_balances VALUES('audit-a','S',5,1),('audit-a','M',99,0),('audit-a','L',0,1),('audit-a','XL',100,1),('deleted','M',50,1); INSERT INTO checkout_reservations VALUES('audit-a','S',2),('audit-a','XL',1);");
$before = $launchDB->query('SELECT * FROM inventory_balances ORDER BY product_id,size')->fetchAll();
$reservations = $launchDB->query('SELECT * FROM checkout_reservations ORDER BY product_id,size')->fetchAll();
$availability = mahara_catalog_availability();
launchVerify($availability['products'][0]['variants'] === [
    ['size' => 'S', 'status' => 'available', 'maxQty' => 3],
    ['size' => 'M', 'status' => 'untracked', 'maxQty' => 0],
    ['size' => 'L', 'status' => 'out', 'maxQty' => 0],
    ['size' => 'XL', 'status' => 'available', 'maxQty' => 20],
], 'Public availability subtracts reservations, respects confirmed stock and bounds cart quantities.');
launchVerify(count($availability['products']) === 2 && $availability['products'][1]['variants'][0]['status'] === 'untracked', 'Only published products are exposed; unknown variants remain untracked.');
launchVerify($before === $launchDB->query('SELECT * FROM inventory_balances ORDER BY product_id,size')->fetchAll(), 'Reading availability never changes balances.');
launchVerify($reservations === $launchDB->query('SELECT * FROM checkout_reservations ORDER BY product_id,size')->fetchAll(), 'Reading availability never releases reservations.');

echo "PASS: $checks launch-readiness checks (legal drafts/publication/escaping and read-only availability).\n";
