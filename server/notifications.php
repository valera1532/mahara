<?php
declare(strict_types=1);

// Transactional mail only. Nothing is sent by a web request or unless explicitly
// enabled in private configuration. A CLI/cron worker delivers the private queue.
function mahara_mail_config(): array {
    $config = array_merge(['enabled' => false, 'host' => '', 'port' => 465, 'encryption' => 'tls', 'from' => '', 'from_name' => 'MAHARA', 'shop_email' => ''], mahara_config()['mail'] ?? []);
    if (defined('MAHARA_LIBRARY_ONLY') && isset($GLOBALS['mahara_mail_test_config'])) $config = array_merge($config, $GLOBALS['mahara_mail_test_config']);
    return $config;
}

function mahara_mail_secrets(): array {
    if (defined('MAHARA_LIBRARY_ONLY') && isset($GLOBALS['mahara_mail_test_secrets'])) return $GLOBALS['mahara_mail_test_secrets'];
    $app = mahara_config();
    $path = getenv('MAHARA_MAIL_SECRETS') ?: ($app['production'] ? $app['data_dir'] . '/mail-secrets.php' : $app['root'] . '/.local/mail-secrets.php');
    if (!is_file($path) || is_link($path)) return ['username' => '', 'password' => ''];
    ob_start();
    try { $secrets = require $path; }
    catch (Throwable) { throw new RuntimeException('Invalid private mail configuration.'); }
    finally { ob_end_clean(); }
    if (!is_array($secrets) || !is_string($secrets['username'] ?? null) || !is_string($secrets['password'] ?? null)) throw new RuntimeException('Invalid private mail configuration.');
    return ['username' => $secrets['username'], 'password' => $secrets['password']];
}

function mahara_mail_ready(): bool {
    $config = mahara_mail_config();
    if ($config['enabled'] !== true || !is_string($config['host']) || !preg_match('/^(?=.{1,253}$)[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/D', $config['host']) || !is_int($config['port']) || !in_array($config['port'], [465, 587], true) || !in_array($config['encryption'], ['tls', 'starttls'], true) || !filter_var($config['from'], FILTER_VALIDATE_EMAIL)) return false;
    if ($config['shop_email'] !== '' && !filter_var($config['shop_email'], FILTER_VALIDATE_EMAIL)) return false;
    $origin = mahara_config()['origin'];
    if (!$origin || (mahara_config()['production'] && !str_starts_with($origin, 'https://'))) return false;
    $secrets = mahara_mail_secrets();
    return $secrets['username'] !== '' && $secrets['password'] !== '';
}

function mahara_notifications_schema(): void {
    static $ready = false;
    if ($ready) return;
    mahara_payments_schema();
    mahara_db()->exec('CREATE TABLE IF NOT EXISTS order_mail_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT, dedup_key TEXT NOT NULL UNIQUE,
        order_id TEXT NOT NULL REFERENCES checkout_orders(id), event TEXT NOT NULL,
        recipient TEXT NOT NULL, audience TEXT NOT NULL, status TEXT NOT NULL DEFAULT \'pending\',
        attempts INTEGER NOT NULL DEFAULT 0, available_at INTEGER NOT NULL, created_at INTEGER NOT NULL,
        sent_at INTEGER, lease TEXT, link_token TEXT, link_expires INTEGER
    ); CREATE INDEX IF NOT EXISTS order_mail_pending ON order_mail_queue(status,available_at);
    CREATE TABLE IF NOT EXISTS order_recovery_tokens (
        token_hash TEXT PRIMARY KEY, order_id TEXT NOT NULL REFERENCES checkout_orders(id),
        expires_at INTEGER NOT NULL, used_at INTEGER
    ); CREATE INDEX IF NOT EXISTS order_recovery_expiry ON order_recovery_tokens(expires_at);');
    $ready = true;
}

function mahara_order_notification_schedule(string $id, string $event, string $occurrence = ''): void {
    if (!in_array($event, ['order_created', 'payment_paid', 'refund_completed', 'order_shipped', 'order_completed', 'order_tracking_updated'], true) || !mahara_mail_ready()) return;
    mahara_notifications_schema();
    $row = mahara_payments_row($id);
    $customer = json_decode($row['customer_json'], true, 64, JSON_THROW_ON_ERROR);
    $recipients = ['customer' => $customer['email'] ?? '', 'shop' => mahara_mail_config()['shop_email']];
    foreach ($recipients as $audience => $email) {
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $key = $id . ':' . $event . ($occurrence !== '' ? ':' . $occurrence : '') . ':' . $audience;
        mahara_query('INSERT OR IGNORE INTO order_mail_queue(dedup_key,order_id,event,recipient,audience,available_at,created_at) VALUES(?,?,?,?,?,?,?)', [$key, $id, $event, $email, $audience, time(), time()]);
    }
}

function mahara_order_recovery_request(mixed $input): void {
    mahara_object($input, ['number', 'email'], 'Восстановление доступа');
    $number = mahara_text($input['number'], 'Номер заказа', 100, true);
    $email = mahara_text($input['email'], 'Электронная почта', 254, true);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) mahara_fail('Проверьте электронную почту.');
    if (!mahara_mail_ready()) throw new MaharaHttpError(503, 'Восстановление по почте пока недоступно. Обратитесь в магазин.');
    mahara_notifications_schema();
    // Identical response for existing and nonexistent orders; do not enumerate.
    $row = mahara_query('SELECT id,customer_json FROM checkout_orders WHERE number=?', [$number])->fetch();
    if (!$row) return;
    $customer = json_decode($row['customer_json'], true, 64, JSON_THROW_ON_ERROR);
    if (!is_string($customer['email'] ?? null) || strcasecmp($customer['email'], $email) !== 0) return;
    // Queue to the stored checkout address, never an arbitrary recovery target.
    $key = $row['id'] . ':recovery:' . intdiv(time(), 900);
    mahara_query('INSERT OR IGNORE INTO order_mail_queue(dedup_key,order_id,event,recipient,audience,available_at,created_at) VALUES(?,?,?,?,?,?,?)', [$key, $row['id'], 'access_recovery', $customer['email'], 'customer', time(), time()]);
}

function mahara_order_recovery_exchange(mixed $input): array {
    mahara_object($input, ['token'], 'Ссылка на заказ');
    $token = $input['token'];
    if (!is_string($token) || !preg_match('/^[a-zA-Z0-9_-]{43}$/D', $token)) throw new MaharaHttpError(404, 'Ссылка недействительна или уже использована. Запросите новую.');
    mahara_notifications_schema();
    $db = mahara_db(); $db->exec('BEGIN IMMEDIATE');
    try {
        $row = mahara_query('SELECT order_id FROM order_recovery_tokens WHERE token_hash=? AND expires_at>? AND used_at IS NULL', [hash('sha256', $token), time()])->fetch();
        if (!$row) throw new MaharaHttpError(404, 'Ссылка недействительна или уже использована. Запросите новую.');
        mahara_query('UPDATE order_recovery_tokens SET used_at=? WHERE token_hash=? AND used_at IS NULL', [time(), hash('sha256', $token)]);
        $order = mahara_payments_row($row['order_id']);
        $db->exec('COMMIT');
        return mahara_payments_view($order) + ['accessToken' => $order['access_token']];
    } catch (Throwable $error) { try { $db->exec('ROLLBACK'); } catch (Throwable) {} throw $error; }
}

function mahara_notifications_api(string $path, string $method): void {
    if ($path === '/api/checkout/recovery/config' && $method === 'GET') mahara_json(['enabled' => mahara_mail_ready()]);
    if (in_array($path, ['/api/checkout/recovery/request', '/api/checkout/recovery/exchange'], true) && $method === 'POST') {
        mahara_require_origin();
        // Raw IP is not persisted; proxy ambiguity errs on the stricter limit.
        $key = hash('sha256', 'order-recovery:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $retry = mahara_throttle($key, 10);
        if ($retry) { header('Retry-After: ' . $retry); throw new MaharaHttpError(429, 'Слишком много запросов. Попробуйте позже.'); }
        $input = mahara_read_json(4096);
        if (str_ends_with($path, '/exchange')) mahara_json(mahara_order_recovery_exchange($input));
        mahara_order_recovery_request($input);
        mahara_json(['message' => 'Если номер и почта совпали с заказом, мы отправим ссылку для доступа. Проверьте также папку «Спам».']);
    }
    if (str_starts_with($path, '/api/checkout/recovery/')) throw new MaharaHttpError(405, 'Метод не поддерживается.');
}

function mahara_order_mail_message(array $job, array $row): array {
    $labels = ['order_created' => 'Заказ создан', 'payment_paid' => 'Оплата подтверждена', 'refund_completed' => 'Возврат денег подтверждён', 'order_shipped' => 'Заказ передан в доставку', 'order_completed' => 'Заказ завершён', 'order_tracking_updated' => 'Номер отправления обновлён', 'access_recovery' => 'Доступ к вашему заказу'];
    $label = $labels[$job['event']] ?? 'Информация о заказе';
    $body = $label . ".\nНомер заказа: " . $row['number'] . "\n\n";
    foreach (json_decode($row['items_json'], true, 64, JSON_THROW_ON_ERROR) as $item) $body .= $item['title'] . ' · ' . $item['size'] . ' × ' . $item['qty'] . "\n";
    $body .= "\nСтоимость товаров и оплачиваемых на сайте услуг: " . number_format((int)$row['total_amount'] / 100, 2, ',', ' ') . " ₽.\n";
    $delivery = json_decode($row['delivery_json'], true, 64, JSON_THROW_ON_ERROR);
    if (!empty($delivery['quoteId'])) $body .= "Доставка СДЭК оплачивается отдельно перевозчику.\n";
    if ($row['tracking_number'] !== '' && in_array($job['event'], ['order_shipped', 'order_completed', 'order_tracking_updated'], true)) {
        $body .= 'Номер отправления: ' . $row['tracking_number'] . "\nОтследить в СДЭК (введите номер отправления): https://www.cdek.ru/ru/tracking/\n";
    }
    if ($job['audience'] === 'customer') $body .= "\nОткрыть заказ (одноразовая ссылка действует 24 часа):\n" . mahara_config()['origin'] . '/checkout.html#access=' . $job['link_token'] . "\n";
    else $body .= "\nЗаказ в панели магазина: " . mahara_config()['origin'] . '/orders.html' . "\n";
    $body .= "\nУсловия возврата: " . mahara_config()['origin'] . "/returns.html\n";
    $body .= "\nЭто сообщение о заказе, не рекламная рассылка и не кассовый чек. Не передавайте ссылку на заказ другим людям.\n";
    return ['subject' => 'MAHARA — ' . $label . ' ' . $row['number'], 'body' => $body];
}

function mahara_smtp_send(string $recipient, string $subject, string $body, string $messageId): void {
    if (defined('MAHARA_LIBRARY_ONLY') && isset($GLOBALS['mahara_mail_test_transport'])) {
        ($GLOBALS['mahara_mail_test_transport'])($recipient, $subject, $body, $messageId); return;
    }
    $config = mahara_mail_config(); $secrets = mahara_mail_secrets();
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid mail recipient.');
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $config['host'], 'SNI_enabled' => true]]);
    $socket = @stream_socket_client(($config['encryption'] === 'tls' ? 'tls://' : 'tcp://') . $config['host'] . ':' . $config['port'], $errorCode, $errorText, 10, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) throw new RuntimeException('Mail transport unavailable.');
    stream_set_timeout($socket, 10);
    $read = static function (array $expected) use ($socket): void {
        for ($lines = 0; $lines < 100; $lines++) {
            $line = fgets($socket, 2048);
            if ($line === false || !preg_match('/^([0-9]{3})([ -])/', $line, $match)) throw new RuntimeException('Mail transport response invalid.');
            if ($match[2] === ' ') {
                if (!in_array((int)$match[1], $expected, true)) throw new RuntimeException('Mail transport rejected command.');
                return;
            }
        }
        throw new RuntimeException('Mail transport response too long.');
    };
    $write = static function (string $data) use ($socket): void {
        $length = strlen($data); $offset = 0;
        while ($offset < $length) { $written = fwrite($socket, substr($data, $offset)); if (!$written) throw new RuntimeException('Mail transport write failed.'); $offset += $written; }
    };
    $command = static function (string $line, array $expected) use ($write, $read): void { $write($line . "\r\n"); $read($expected); };
    try {
        $read([220]); $command('EHLO mahara.local', [250]);
        if ($config['encryption'] === 'starttls') {
            $command('STARTTLS', [220]);
            if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) throw new RuntimeException('Mail TLS unavailable.');
            $command('EHLO mahara.local', [250]);
        }
        $command('AUTH LOGIN', [334]); $command(base64_encode($secrets['username']), [334]); $command(base64_encode($secrets['password']), [235]);
        $command('MAIL FROM:<' . $config['from'] . '>', [250]); $command('RCPT TO:<' . $recipient . '>', [250, 251]); $command('DATA', [354]);
        $encoded = static fn(string $value): string => '=?UTF-8?B?' . base64_encode($value) . '?=';
        $headers = ['Date: ' . gmdate('D, d M Y H:i:s') . ' +0000', 'From: ' . $encoded((string)$config['from_name']) . ' <' . $config['from'] . '>', 'To: <' . $recipient . '>', 'Subject: ' . $encoded($subject), 'Message-ID: <' . $messageId . '@' . parse_url(mahara_config()['origin'], PHP_URL_HOST) . '>', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64'];
        $write(implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n") . ".\r\n"); $read([250]);
        // Delivery has succeeded. A failed QUIT must never enqueue another copy.
        @fwrite($socket, "QUIT\r\n");
    } finally { fclose($socket); }
}

function mahara_notifications_work(int $limit = 3): array {
    if (!mahara_mail_ready()) return ['enabled' => false, 'sent' => 0, 'failed' => 0];
    mahara_notifications_schema(); $sent = 0; $failed = 0;
    mahara_query("UPDATE order_mail_queue SET status='failed',lease=NULL WHERE status='sending' AND available_at<=? AND attempts>=8", [time()]);
    // Both request/recovery endpoints and this worker store capabilities only in
    // the private DB. A bearer is never included in CLI output or URL query logs.
    for ($n = 0; $n < max(1, min(20, $limit)); $n++) {
        $db = mahara_db(); $db->exec('BEGIN IMMEDIATE');
        try {
            $job = mahara_query("SELECT * FROM order_mail_queue WHERE (status='pending' OR status='sending') AND available_at<=? AND attempts<8 ORDER BY id LIMIT 1", [time()])->fetch();
            if (!$job) { $db->exec('COMMIT'); break; }
            $lease = mahara_random_token();
            if ($job['audience'] === 'customer' && (!$job['link_token'] || (int)$job['link_expires'] <= time())) {
                $job['link_token'] = mahara_random_token(); $job['link_expires'] = time() + 86400;
                mahara_query('INSERT INTO order_recovery_tokens(token_hash,order_id,expires_at) VALUES(?,?,?)', [hash('sha256', $job['link_token']), $job['order_id'], $job['link_expires']]);
            }
            mahara_query("UPDATE order_mail_queue SET status='sending',lease=?,attempts=attempts+1,available_at=?,link_token=?,link_expires=? WHERE id=?", [$lease, time() + 600, $job['link_token'], $job['link_expires'], $job['id']]);
            $db->exec('COMMIT');
        } catch (Throwable $error) { try { $db->exec('ROLLBACK'); } catch (Throwable) {} throw $error; }
        try {
            $message = mahara_order_mail_message($job, mahara_payments_row($job['order_id']));
            mahara_smtp_send($job['recipient'], $message['subject'], $message['body'], 'order-' . $job['id']);
            mahara_query("UPDATE order_mail_queue SET status='sent',sent_at=?,lease=NULL,link_token=NULL,recipient='' WHERE id=? AND lease=?", [time(), $job['id'], $lease]); $sent++;
        } catch (Throwable) {
            mahara_query("UPDATE order_mail_queue SET status=CASE WHEN attempts>=8 THEN 'failed' ELSE 'pending' END,available_at=?,lease=NULL WHERE id=? AND lease=?", [time() + min(86400, 300 * (2 ** min(8, (int)$job['attempts']))), $job['id'], $lease]); $failed++;
        }
    }
    mahara_query('DELETE FROM order_recovery_tokens WHERE expires_at<?', [time() - 86400]);
    return ['enabled' => true, 'sent' => $sent, 'failed' => $failed];
}
