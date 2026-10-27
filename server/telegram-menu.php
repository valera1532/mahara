<?php
declare(strict_types=1);

// Read-only owner menu. The caller has already committed subscription updates.
function mahara_telegram_menu_filters(): array {
    return [
        'ready' => ['label' => 'На отправку', 'where' => "status='paid' AND fulfillment_status='processing'"],
        'unpaid' => ['label' => 'Созданы, ждут оплаты', 'where' => "status='payment_pending'"],
        'shipped' => ['label' => 'Отправлены', 'where' => "fulfillment_status='shipped'"],
        'completed' => ['label' => 'Завершены', 'where' => "fulfillment_status='completed'"],
        'all' => ['label' => 'Все заказы', 'where' => '1=1'],
    ];
}

function mahara_telegram_menu_send_home(string $chatId): array {
    $config = mahara_telegram_config();
    if ($config['enabled'] !== true || !preg_match('/^[1-9][0-9]{0,19}$/D', $chatId)) throw new MaharaTelegramDeliveryError();
    $subscriber = mahara_query('SELECT username FROM order_telegram_subscribers WHERE chat_id=? AND active=1', [$chatId])->fetch();
    if (!$subscriber || (!in_array($subscriber['username'], mahara_telegram_allowed_usernames(), true) && !($subscriber['username'] === '' && $chatId === $config['chat_id']))) throw new MaharaTelegramDeliveryError();
    $result = mahara_telegram_request('sendMessage', ['chat_id' => $chatId] + mahara_telegram_menu_view() + ['link_preview_options' => ['is_disabled' => true]]);
    if (!is_int($result['message_id'] ?? null) || $result['message_id'] <= 0) throw new MaharaTelegramDeliveryError();
    return ['message_id' => $result['message_id']];
}

function mahara_telegram_menu_view(string $filter = '', int $page = 0, string $id = ''): array {
    $filters = mahara_telegram_menu_filters();
    if ($filter === '') {
        $buttons = [];
        foreach ($filters as $key => $definition) $buttons[] = [['text' => $definition['label'], 'callback_data' => 'mm:l:' . $key . ':0']];
        return ['text' => "MAHARA — заказы\nВыберите список, чтобы посмотреть заказ, получателя и доставку.", 'reply_markup' => ['inline_keyboard' => $buttons]];
    }
    if (!isset($filters[$filter]) || $page < 0 || $page > 999999 || ($id !== '' && !preg_match('/^[a-f0-9]{32}$/D', $id))) throw new MaharaTelegramDeliveryError();
    $menu = ['text' => 'В меню', 'callback_data' => 'mm:home'];
    if ($id !== '') {
        $row = mahara_query('SELECT id,number,items_json,customer_json,delivery_json,total_amount,status,fulfillment_status,tracking_number FROM checkout_orders WHERE id=?', [$id])->fetch();
        $buttons = [[['text' => 'Назад к списку', 'callback_data' => 'mm:l:' . $filter . ':' . $page]], [$menu]];
        if (!$row) return ['text' => 'Заказ не найден. Вернитесь к списку и обновите его.', 'reply_markup' => ['inline_keyboard' => $buttons]];
        $event = match ($row['fulfillment_status']) { 'shipped' => 'order_shipped', 'completed' => 'order_completed', default => 'order_details' };
        array_splice($buttons, 1, 0, [[['text' => 'Открыть в админке', 'url' => mahara_config()['origin'] . '/orders.html?order=' . $id]]]);
        return ['text' => mahara_telegram_message(['event' => $event], $row), 'reply_markup' => ['inline_keyboard' => $buttons]];
    }
    $where = $filters[$filter]['where'];
    $count = (int)mahara_query('SELECT COUNT(*) FROM checkout_orders WHERE ' . $where)->fetchColumn();
    $pages = max(1, (int)ceil($count / 5)); $page = min($page, $pages - 1);
    $rows = mahara_query('SELECT id,number,total_amount,status,fulfillment_status FROM checkout_orders WHERE ' . $where . ' ORDER BY created_at DESC,id DESC LIMIT 5 OFFSET ?', [$page * 5])->fetchAll();
    $text = 'MAHARA — ' . $filters[$filter]['label'] . "\nЗаказов: " . $count . "\nСтраница " . ($page + 1) . ' из ' . $pages . "\n";
    $payment = ['payment_pending' => 'ждёт оплаты', 'paid' => 'оплачен', 'refund_pending' => 'возврат в обработке', 'refunded' => 'возвращён', 'partial_refunded' => 'частичный возврат', 'canceled' => 'отменён', 'expired' => 'срок оплаты истёк', 'failed' => 'ошибка оплаты'];
    $fulfillment = ['processing' => 'в обработке', 'shipped' => 'отправлен', 'completed' => 'завершён'];
    $buttons = [];
    foreach ($rows as $index => $row) {
        $number = mahara_telegram_bounded_text($row['number'], 100);
        $text .= "\n" . ($page * 5 + $index + 1) . '. ' . $number . ' — ' . number_format((int)$row['total_amount'] / 100, 2, ',', ' ') . " ₽\n";
        $text .= ($payment[$row['status']] ?? 'проверьте оплату') . ' · ' . ($fulfillment[$row['fulfillment_status']] ?? 'проверьте доставку') . "\n";
        $buttons[] = [['text' => 'Открыть ' . mahara_telegram_bounded_text($row['number'], 70), 'callback_data' => 'mm:d:' . $row['id'] . ':' . $filter . ':' . $page]];
    }
    if (!$rows) $text .= "\nВ этом списке пока нет заказов.\n";
    $navigation = [];
    if ($page > 0) $navigation[] = ['text' => '← Назад', 'callback_data' => 'mm:l:' . $filter . ':' . ($page - 1)];
    if ($page + 1 < $pages) $navigation[] = ['text' => 'Далее →', 'callback_data' => 'mm:l:' . $filter . ':' . ($page + 1)];
    if ($navigation) $buttons[] = $navigation;
    $buttons[] = [['text' => 'Обновить', 'callback_data' => 'mm:l:' . $filter . ':' . $page], $menu];
    return ['text' => $text, 'reply_markup' => ['inline_keyboard' => $buttons]];
}

function mahara_telegram_menu_handle(array $update): bool {
    $config = mahara_telegram_config();
    if ($config['enabled'] !== true) return false;
    $callback = is_array($update['callback_query'] ?? null) ? $update['callback_query'] : null;
    $message = $callback !== null ? ($callback['message'] ?? null) : ($update['message'] ?? null);
    $sender = $callback !== null ? ($callback['from'] ?? null) : ($message['from'] ?? null);
    if (!is_array($message) || !is_array($sender) || ($message['chat']['type'] ?? null) !== 'private' || !is_int($message['chat']['id'] ?? null)
        || $message['chat']['id'] <= 0 || ($sender['id'] ?? null) !== $message['chat']['id'] || ($sender['is_bot'] ?? null) !== false
        || !is_string($sender['username'] ?? null) || !in_array(strtolower($sender['username']), mahara_telegram_allowed_usernames(), true)) return false;
    $chat = (string)$message['chat']['id'];
    $subscriber = mahara_query('SELECT username FROM order_telegram_subscribers WHERE chat_id=? AND active=1', [$chat])->fetch();
    if (!$subscriber || ($subscriber['username'] !== strtolower($sender['username']) && !($subscriber['username'] === '' && $chat === $config['chat_id']))) return false;
    $filter = ''; $page = 0; $id = '';
    if ($callback !== null) {
        if (!is_string($callback['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $callback['id']) || !is_int($message['message_id'] ?? null) || $message['message_id'] <= 0
            || !is_string($callback['data'] ?? null) || strlen($callback['data']) > 64) return false;
        if ($callback['data'] !== 'mm:home') {
            if (preg_match('/^mm:l:(ready|unpaid|shipped|completed|all):(0|[1-9][0-9]{0,5})$/D', $callback['data'], $match)) { $filter = $match[1]; $page = (int)$match[2]; }
            elseif (preg_match('/^mm:d:([a-f0-9]{32}):(ready|unpaid|shipped|completed|all):(0|[1-9][0-9]{0,5})$/D', $callback['data'], $match)) { $id = $match[1]; $filter = $match[2]; $page = (int)$match[3]; }
            else return false;
        }
    } else {
        if (!is_string($message['text'] ?? null) || strlen($message['text']) > 512 || !preg_match('/^\/(menu|start)(?:@([a-zA-Z0-9_]{5,32}))?(?:\s[^\r\n]*)?$/D', trim($message['text']), $match)
            || (($match[2] ?? '') !== '' && strcasecmp($match[2], $config['bot_username']) !== 0)) return false;
    }
    $view = mahara_telegram_menu_view($filter, $page, $id);
    $payload = ['chat_id' => $chat] + $view + ['link_preview_options' => ['is_disabled' => true]];
    if ($callback === null) mahara_telegram_request('sendMessage', $payload);
    else {
        // An old callback can expire; acknowledging it must not block the requested view.
        try { mahara_telegram_request('answerCallbackQuery', ['callback_query_id' => $callback['id']]); } catch (Throwable) {}
        if (($message['text'] ?? null) !== $view['text'] || ($message['reply_markup'] ?? null) != $view['reply_markup']) mahara_telegram_request('editMessageText', $payload + ['message_id' => $message['message_id']]);
    }
    return true;
}
