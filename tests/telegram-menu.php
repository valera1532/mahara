<?php
declare(strict_types=1);

if (($argv[1] ?? '') !== '--worker') {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mahara-telegram-menu-' . bin2hex(random_bytes(12));
    if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create menu fixture directory.');
    putenv('MAHARA_TELEGRAM_MENU_TEST_DIR=' . $directory); $status = 1;
    try {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Cannot start menu fixture.');
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
        echo $output; if ($errors !== '') fwrite(STDERR, $errors);
    } finally {
        $resolved = realpath($directory); $temporaryRoot = realpath(sys_get_temp_dir());
        if ($resolved === false || $temporaryRoot === false || dirname($resolved) !== $temporaryRoot || !str_starts_with(basename($resolved), 'mahara-telegram-menu-')) throw new RuntimeException('Unsafe menu fixture cleanup.');
        foreach (['mahara.sqlite', 'mahara.sqlite-wal', 'mahara.sqlite-shm', 'menu-worker.log'] as $name) {
            $file = $resolved . DIRECTORY_SEPARATOR . $name;
            if (is_file($file) && !is_link($file)) unlink($file);
        }
        rmdir($resolved);
    }
    exit($status);
}
$directory = getenv('MAHARA_TELEGRAM_MENU_TEST_DIR');
if (!$directory || !is_dir($directory) || !str_starts_with(basename($directory), 'mahara-telegram-menu-')) throw new RuntimeException('Missing isolated menu fixture.');
$GLOBALS['mahara_app_test_config'] = ['root' => dirname(__DIR__), 'data_dir' => $directory, 'origin' => 'https://shop.example.test', 'production' => false, 'trust_proxy' => false, 'session_ttl' => 28800, 'login_window' => 900, 'login_pair_limit' => 8, 'login_ip_limit' => 40, 'mail' => [], 'payments' => ['enabled' => false], 'shipping' => ['enabled' => false]];
$GLOBALS['mahara_telegram_test_config'] = ['enabled' => true, 'chat_id' => '', 'allowed_usernames' => ['Valerooonn1532', 'serg_kharakhorin'], 'bot_username' => 'MaharaRussia_bot'];
$GLOBALS['mahara_telegram_test_secrets'] = ['token' => ''];
$GLOBALS['mahara_payments_test_secrets'] = ['terminal_key' => '', 'password' => ''];
$GLOBALS['mahara_shipping_test_secrets'] = ['account' => '', 'password' => ''];
$GLOBALS['mahara_payments_test_transport'] = $GLOBALS['mahara_shipping_test_transport'] = static function (): never { throw new RuntimeException('Menu must not contact payment or delivery providers.'); };
define('MAHARA_LIBRARY_ONLY', true);
require_once dirname(__DIR__) . '/server/app.php';
require_once dirname(__DIR__) . '/server/telegram.php';
$checks = 0; $requests = []; $transportMode = 'success'; $updates = []; $polls = [];
function menu_verify(bool $condition, string $message): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function menu_message(string $text = '/menu', int $chat = 10101, string $username = 'Valerooonn1532'): array {
    return ['message' => ['message_id' => 10, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat, 'is_bot' => false, 'username' => $username], 'text' => $text]];
}
function menu_callback(string $data): array {
    return ['callback_query' => ['id' => 'fixture_callback_1', 'from' => ['id' => 10101, 'is_bot' => false, 'username' => 'Valerooonn1532'], 'message' => ['message_id' => 700, 'chat' => ['id' => 10101, 'type' => 'private'], 'text' => 'Old menu'], 'data' => $data]];
}
function menu_state(): array {
    $state = [];
    foreach (['checkout_orders', 'checkout_reservations', 'checkout_refund_attempts', 'inventory_meta', 'inventory_balances', 'inventory_movements', 'order_telegram_queue', 'order_telegram_subscribers', 'order_telegram_state'] as $table) $state[$table] = mahara_query('SELECT * FROM ' . $table . ' ORDER BY rowid')->fetchAll();
    return $state;
}
function menu_order_buttons(array $view): array {
    return array_values(array_filter(array_merge(...$view['reply_markup']['inline_keyboard']), static fn(array $button): bool => str_starts_with($button['callback_data'] ?? '', 'mm:d:')));
}
$GLOBALS['mahara_telegram_test_transport'] = static function (string $method, array $payload) use (&$requests, &$transportMode, &$updates, &$polls): array {
    menu_verify(in_array($method, ['sendMessage', 'editMessageText', 'answerCallbackQuery', 'getUpdates'], true), 'The integrated menu uses only its documented Telegram methods.');
    menu_verify(!mahara_db()->inTransaction(), 'Menu transport runs outside SQLite write transactions.');
    if ($method === 'getUpdates') {
        $polls[] = $payload;
        menu_verify($payload['allowed_updates'] === ['message', 'callback_query'] && $payload['timeout'] === 0 && $payload['limit'] === 100, 'Polling receives both commands and button callbacks with bounded requests.');
        return ['ok' => true, 'result' => $updates];
    }
    $requests[] = compact('method', 'payload');
    if ($transportMode === 'failure' || ($transportMode === 'ack-failure' && $method === 'answerCallbackQuery') || ($transportMode === 'menu-failure' && isset($payload['reply_markup']))) throw new RuntimeException('PRIVATE_PROVIDER_TOKEN_FAILURE');
    if ($transportMode === 'not-modified' && $method === 'editMessageText') return ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is not modified: specified new message content and reply markup are exactly the same'];
    if ($transportMode === 'edit-rejected' && $method === 'editMessageText') return ['ok' => false, 'error_code' => 400, 'description' => 'PRIVATE_UNRELATED_ERROR'];
    return ['ok' => true, 'result' => $method === 'answerCallbackQuery' ? true : ['message_id' => 700]];
};
mahara_telegram_schema();
menu_verify(mahara_config()['data_dir'] === $directory, 'The menu test uses a complete isolated app configuration.');
menu_verify(str_contains(mahara_telegram_menu_view('ready')['text'], 'пока нет заказов'), 'Empty order filters render a useful empty state.');
$definitions = array_merge(array_fill(0, 6, ['paid', 'processing']), array_fill(0, 2, ['payment_pending', 'processing']), [['paid', 'shipped'], ['refunded', 'shipped'], ['paid', 'completed'], ['canceled', 'processing'], ['expired', 'processing'], ['failed', 'processing'], ['refund_pending', 'processing']]);
$now = time();
foreach ($definitions as $index => [$status, $fulfillment]) {
    $number = $index + 1;
    $order = [
        'id' => sprintf('%032x', $number), 'number' => 'MENU-' . $number, 'request_id' => 'menu-request-' . $number, 'request_payload' => '{}', 'access_token' => 'PRIVATE-CUSTOMER-BEARER',
        'items_json' => json_encode([['id' => 'hoodie-black', 'title' => 'Тестовое худи', 'size' => 'M', 'qty' => 2, 'unitAmount' => 1000, 'amount' => 2000]], JSON_THROW_ON_ERROR),
        'customer_json' => json_encode(['name' => 'Тестовый получатель', 'phone' => '+79990000000', 'email' => 'PRIVATE-EMAIL@example.test'], JSON_THROW_ON_ERROR),
        'delivery_json' => json_encode(['city' => 'Москва', 'point' => 'MSK-FIXTURE', 'address' => 'Тестовая улица, 1', 'quoteId' => 'PRIVATE-QUOTE'], JSON_THROW_ON_ERROR),
        'total_amount' => 2000, 'delivery_amount' => 0, 'status' => $status, 'bank_status' => 'PRIVATE-BANK-STATUS', 'terminal_key' => 'PRIVATE-TERMINAL', 'payment_id' => 'PRIVATE-PAYMENT-' . $number,
        'payment_url' => 'https://bank.example.test/PRIVATE-URL', 'qr_svg' => 'PRIVATE-QR', 'stock_applied' => 1, 'expires_at' => $now + 900,
        'last_checked' => $now, 'created_at' => $now + $number, 'updated_at' => $now, 'tracking_number' => 'FIXTURE-TRACK', 'fulfillment_status' => $fulfillment,
    ];
    mahara_query('INSERT INTO checkout_orders(' . implode(',', array_keys($order)) . ') VALUES(' . implode(',', array_fill(0, count($order), '?')) . ')', array_values($order));
}
foreach ([['10101', 'valerooonn1532', 1], ['20202', 'serg_kharakhorin', 1], ['30303', 'unknown_visitor', 1], ['40404', 'serg_kharakhorin', 0]] as [$chat, $username, $active]) mahara_query('INSERT INTO order_telegram_subscribers(chat_id,username,active,updated_at) VALUES(?,?,?,?)', [$chat, $username, $active, $now]);
$unchanged = menu_state();
menu_verify(mahara_telegram_menu_send_home('20202') === ['message_id' => 700], 'The CLI home sender returns only delivery proof for an active approved owner.');
$before = count($requests);
foreach (['30303', '40404', '50505', '-10101'] as $deniedChat) {
    try { mahara_telegram_menu_send_home($deniedChat); throw new LogicException('Expected denied home recipient.'); }
    catch (MaharaTelegramDeliveryError) { menu_verify(count($requests) === $before, 'The CLI helper never sends home menus to unknown, inactive or public chats.'); }
}
menu_verify(mahara_telegram_menu_handle(menu_message()), 'An active owner can open the menu.');
$home = end($requests)['payload'];
menu_verify(end($requests)['method'] === 'sendMessage' && count($home['reply_markup']['inline_keyboard']) === 5 && !str_contains($home['text'], 'получатель:'), 'The home menu has five filters without exposing order data.');
menu_verify(mahara_telegram_menu_handle(menu_message('/start@MaharaRussia_bot binding-code', 20202, 'SERG_KHARAKHORIN')), 'Start opens the same menu for the second active owner, case-insensitively.');
$before = count($requests);
foreach ([menu_message('/menu', 30303, 'unknown_visitor'), menu_message('/menu', 40404, 'serg_kharakhorin'), menu_message('/menu', 50505, 'serg_kharakhorin'), menu_message('/menu@Other_test_bot'), menu_message('/stop'), menu_message('hello'), menu_message('/menu', 10101, 'serg_kharakhorin')] as $invalid) menu_verify(!mahara_telegram_menu_handle($invalid), 'Unknown, inactive, mismatched identities and unrelated commands cannot use the menu.');
$invalid = menu_message(); $invalid['message']['chat']['type'] = 'group'; menu_verify(!mahara_telegram_menu_handle($invalid), 'Public groups cannot browse orders.');
$invalid = menu_message(); $invalid['message']['from']['id'] = 20202; menu_verify(!mahara_telegram_menu_handle($invalid), 'Sender and private chat IDs must match.');
$invalid = menu_message(); $invalid['message']['from']['is_bot'] = true; menu_verify(!mahara_telegram_menu_handle($invalid), 'Bots cannot browse orders.');
$GLOBALS['mahara_telegram_test_config']['enabled'] = false;
menu_verify(!mahara_telegram_menu_handle(menu_message()), 'Disabled Telegram disables the menu.');
$GLOBALS['mahara_telegram_test_config']['enabled'] = true;
menu_verify(count($requests) === $before, 'Denied requests never send replies or acknowledgements.');

menu_verify(mahara_telegram_menu_handle(menu_callback('mm:l:ready:0')), 'A valid callback opens the selected filter.');
$view = end($requests)['payload'];
menu_verify(array_column(array_slice($requests, -2), 'method') === ['answerCallbackQuery', 'editMessageText'] && $view['message_id'] === 700, 'Callbacks acknowledge and edit the existing bot message.');
menu_verify(str_contains($view['text'], 'Заказов: 6') && str_contains($view['text'], 'Страница 1 из 2') && count(menu_order_buttons($view)) === 5, 'The ready filter includes paid processing orders, five per page.');
menu_verify(str_contains(menu_order_buttons($view)[0]['callback_data'], sprintf('%032x', 6)) && str_contains(menu_order_buttons($view)[4]['callback_data'], sprintf('%032x', 2)), 'Order lists have stable newest-first ordering.');
$second = mahara_telegram_menu_view('ready', 1);
menu_verify(count(menu_order_buttons($second)) === 1 && str_contains(menu_order_buttons($second)[0]['callback_data'], sprintf('%032x', 1)), 'The second page contains only the remaining order.');
$views = ['ready' => $view, 'unpaid' => mahara_telegram_menu_view('unpaid'), 'shipped' => mahara_telegram_menu_view('shipped'), 'completed' => mahara_telegram_menu_view('completed'), 'all' => mahara_telegram_menu_view('all', 999999)];
menu_verify(count(menu_order_buttons($views['unpaid'])) === 2 && str_contains($views['unpaid']['text'], 'Созданы, ждут оплаты'), 'The unpaid filter contains only orders still waiting for payment.');
menu_verify(count(menu_order_buttons($views['shipped'])) === 2 && str_contains($views['shipped']['text'], 'возвращён'), 'Shipment filtering follows fulfillment status even after a refund.');
menu_verify(count(menu_order_buttons($views['completed'])) === 1, 'Completed orders have their own filter.');
menu_verify(str_contains($views['all']['text'], 'Страница 3 из 3') && count(menu_order_buttons($views['all'])) === 5, 'Out-of-date page numbers clamp to the last page.');
foreach ($views as $list) {
    menu_verify(strlen($list['text']) < 4096 && !str_contains($list['text'], '+79990000000') && !str_contains($list['text'], 'Тестовая улица'), 'Lists are bounded and reserve recipient details for an explicit order view.');
    foreach (array_merge(...$list['reply_markup']['inline_keyboard']) as $button) if (isset($button['callback_data'])) menu_verify(strlen($button['callback_data']) <= 64, 'Every callback fits the Telegram 64-byte limit.');
}

$orderId = sprintf('%032x', 9);
menu_verify(mahara_telegram_menu_handle(menu_callback('mm:d:' . $orderId . ':shipped:0')), 'An order button opens the current persisted order details.');
$details = end($requests)['payload'];
foreach (['Тестовое худи', 'M × 2', 'Тестовый получатель', '+79990000000', 'Москва', 'MSK-FIXTURE', 'Тестовая улица, 1', 'FIXTURE-TRACK'] as $expected) menu_verify(str_contains($details['text'], $expected), 'Order details include the existing safe owner message fields.');
foreach (['PRIVATE-', 'checkout.html', '#access='] as $forbidden) menu_verify(!str_contains(json_encode($details, JSON_THROW_ON_ERROR), $forbidden), 'Order details exclude email, capabilities, payment URLs and provider credentials.');
menu_verify($details['reply_markup']['inline_keyboard'][1][0]['url'] === 'https://shop.example.test/orders.html?order=' . $orderId, 'The admin button uses the authenticated order route without a customer capability.');
$missing = mahara_telegram_menu_view('all', 0, str_repeat('f', 32));
menu_verify(str_contains($missing['text'], 'не найден') && !isset($missing['reply_markup']['inline_keyboard'][1][0]['url']), 'A missing order has a safe return path and no misleading admin link.');
$before = count($requests);
foreach (['mm:l:unknown:0', 'mm:l:ready:-1', 'mm:l:ready:01', 'mm:l:ready:1e3', 'mm:l:ready:1000000', "mm:l:ready' OR 1=1:0", 'mm:d:bad-id:all:0', str_repeat('x', 65), 'mm:refund:' . $orderId] as $data) menu_verify(!mahara_telegram_menu_handle(menu_callback($data)), 'Malformed or mutating callback routes are rejected.');
$invalid = menu_callback('mm:home'); unset($invalid['callback_query']['message']); $invalid['callback_query']['inline_message_id'] = 'PRIVATE-INLINE-ID';
menu_verify(!mahara_telegram_menu_handle($invalid), 'Inline callbacks without an authorized private chat are rejected.');
$invalid = menu_callback('mm:home'); $invalid['callback_query']['from']['username'] = 'unknown_visitor';
menu_verify(!mahara_telegram_menu_handle($invalid) && count($requests) === $before, 'Unauthorized and malformed callbacks disclose no data and produce no API traffic.');
$same = menu_callback('mm:home'); $same['callback_query']['message']['text'] = $home['text']; $same['callback_query']['message']['reply_markup'] = $home['reply_markup'];
menu_verify(mahara_telegram_menu_handle($same) && count($requests) === $before + 1 && end($requests)['method'] === 'answerCallbackQuery', 'An unchanged view is acknowledged without a failing redundant edit.');
$transportMode = 'ack-failure';
menu_verify(mahara_telegram_menu_handle(menu_callback('mm:l:unpaid:0')) && end($requests)['method'] === 'editMessageText', 'Expired callback acknowledgements do not prevent the requested view.');
$transportMode = 'failure';
try { mahara_telegram_menu_handle(menu_message()); throw new LogicException('Expected safe transport failure.'); }
catch (MaharaTelegramDeliveryError $error) { menu_verify($error->getMessage() === 'Telegram delivery unavailable.' && $error->getPrevious() === null, 'Menu failures do not expose provider responses or credentials.'); }
foreach ($requests as $request) if ($request['method'] !== 'answerCallbackQuery') menu_verify(!isset($request['payload']['parse_mode']) && $request['payload']['link_preview_options']['is_disabled'] === true, 'Menu messages use plain text and disabled link previews.');
menu_verify(menu_state() === $unchanged, 'Browsing the menu never mutates orders, inventory, payments, subscriptions, cursor or notification queues.');
$transportMode = 'success';
menu_verify(mahara_telegram_request('answerCallbackQuery', ['callback_query_id' => 'fixture_ack']) === [], 'The real adapter accepts Telegram boolean acknowledgement results.');
$transportMode = 'not-modified';
menu_verify(mahara_telegram_menu_handle(menu_callback('mm:l:ready:0')), 'The real adapter treats an unchanged edit response as successful.');
$transportMode = 'edit-rejected';
try { mahara_telegram_menu_handle(menu_callback('mm:l:ready:0')); throw new LogicException('Expected unrelated edit rejection.'); }
catch (MaharaTelegramDeliveryError $error) { menu_verify($error->getMessage() === 'Telegram delivery unavailable.', 'Unrelated edit failures are not hidden by unchanged-message handling.'); }
$transportMode = 'success';
$updates = [menu_message() + ['update_id' => 100], menu_callback('mm:l:ready:0') + ['update_id' => 101], menu_message('/menu', 30303, 'unknown_visitor') + ['update_id' => 102]];
$before = count($requests); mahara_telegram_poll();
menu_verify(array_column(array_slice($requests, $before), 'method') === ['sendMessage', 'answerCallbackQuery', 'editMessageText'], 'Real polling dispatches a menu command and a button callback while ignoring an unknown user.');
menu_verify(mahara_query("SELECT value FROM order_telegram_state WHERE name='update_cursor'")->fetchColumn() === '102', 'Polling persists a cursor after handling command and callback updates.');
$before = count($requests); mahara_telegram_poll();
menu_verify(count($requests) === $before && end($polls)['offset'] === 103, 'A repeated polling batch cannot duplicate menus or edits.');
$updates = [menu_message('/stop') + ['update_id' => 103], menu_message() + ['update_id' => 104]];
mahara_telegram_poll();
menu_verify(count($requests) === $before && (int)mahara_query('SELECT active FROM order_telegram_subscribers WHERE chat_id=?', ['10101'])->fetchColumn() === 0, 'A committed Stop prevents menu access in the same polling batch.');
$updates = [menu_message('/start') + ['update_id' => 105]];
mahara_telegram_poll();
menu_verify(count($requests) === $before + 1 && end($requests)['method'] === 'sendMessage', 'A fresh Start subscribes first and then displays the menu.');
mahara_telegram_schedule($orderId, 'payment_paid');
$updates = [menu_message() + ['update_id' => 106]]; $transportMode = 'menu-failure';
$previousLog = ini_get('error_log'); ini_set('error_log', $directory . '/menu-worker.log');
try { $summary = mahara_telegram_work(20); }
finally { ini_set('error_log', (string)$previousLog); }
menu_verify($summary === ['enabled' => true, 'sent' => 2, 'failed' => 0] && array_column(array_slice($requests, -2), 'method') === ['sendMessage', 'sendMessage'], 'A failed menu response cannot block queued order alerts for both active owners.');
$log = file_get_contents($directory . '/menu-worker.log');
menu_verify(str_contains($log, 'MAHARA Telegram menu delivery failed.') && !str_contains($log, 'PRIVATE_') && !str_contains($log, 'TOKEN'), 'Menu worker errors contain only a fixed safe diagnostic.');
$after = menu_state();
foreach (['order_telegram_queue', 'order_telegram_subscribers', 'order_telegram_state'] as $table) { unset($after[$table], $unchanged[$table]); }
menu_verify($after === $unchanged, 'End-to-end menu polling and notification delivery leave all commerce records unchanged.');
$GLOBALS['mahara_telegram_test_transport'] = static fn(string $method, array $payload): array => ['ok' => true, 'result' => true];
menu_verify(mahara_telegram_request('setMyCommands', ['commands' => [['command' => 'menu', 'description' => 'Заказы магазина']]]) === [], 'Telegram command configuration accepts the documented boolean success result.');
menu_verify(mahara_telegram_request('setChatMenuButton', ['menu_button' => ['type' => 'commands']]) === [], 'The bot menu button can be configured without treating boolean success as a delivery failure.');
echo 'PASS: ' . $checks . " Telegram menu checks; isolated database and fake transport only.\n";
