<?php
declare(strict_types=1);

// Readiness report only: never print credentials or call payment providers.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$command = $argv[1] ?? 'status';
if (!in_array($command, ['status', 'reconcile'], true)) { fwrite(STDERR, "Usage: php tools/commerce.php status | reconcile [--limit=1]\n"); exit(2); }
define('MAHARA_LIBRARY_ONLY', true);
require_once dirname(__DIR__) . '/server/app.php';
require_once dirname(__DIR__) . '/server/merchant.php';
require_once dirname(__DIR__) . '/server/payments.php';
if (is_file(dirname(__DIR__) . '/server/shipping.php')) require_once dirname(__DIR__) . '/server/shipping.php';
require_once dirname(__DIR__) . '/server/notifications.php';
try {
    if ($command === 'reconcile') {
        $limit = 1;
        if (isset($argv[2])) {
            if (!preg_match('/^--limit=([1-3])$/D', $argv[2], $match) || count($argv) > 3) throw new RuntimeException('Invalid limit.');
            $limit = (int)$match[1];
        }
        if (mahara_payments_config()['enabled'] !== true) { echo "Оплата выключена. Проверка банка не выполнялась.\n"; exit; }
        mahara_payments_reconcile_pending($limit);
        echo "Проверка статусов завершена; лимит заказов: ", $limit, ".\n";
        exit;
    }
    $bank = mahara_payments_secrets();
    $payments = mahara_payments_config();
    $missing = [];
    $hasBank = $bank['terminal_key'] !== '' && $bank['password'] !== '';
    if (!$hasBank) $missing[] = 'Ключ и пароль терминала Т-Кассы';
    if (!mahara_tbank_certificates_ready()) $missing[] = 'Файл доверенных сертификатов банковского API';
    if ($payments['receipt_enabled'] !== true) $missing[] = 'Подтверждение владельцем подключённой кассы и включение передачи чеков';
    if (!in_array($payments['taxation'], ['osn', 'usn_income', 'usn_income_outcome', 'esn', 'patent'], true)) $missing[] = 'Система налогообложения для чека';
    if (!in_array($payments['item_tax'], ['none', 'vat0', 'vat5', 'vat7', 'vat10', 'vat22', 'vat105', 'vat107', 'vat110', 'vat122'], true)) $missing[] = 'Ставка НДС для чека';
    if (!in_array($payments['ffd_version'], ['1.05', '1.2'], true)) $missing[] = 'Версия ФФД для чека';
    $terms = mahara_payments_legal_url($payments['terms_url']); $privacy = mahara_payments_legal_url($payments['privacy_url']);
    if (!$terms || !$privacy) $missing[] = 'Ссылки на условия продажи и политику персональных данных';
    $legal = mahara_merchant_legal_status();
    $legalMissing = array_column(mahara_merchant_missing(), 'label');
    if (!mahara_config()['origin'] || (mahara_config()['production'] && !str_starts_with(mahara_config()['origin'], 'https://'))) $missing[] = 'HTTPS-адрес сайта для уведомлений банка';
    $shippingPublic = function_exists('mahara_shipping_public_config') ? mahara_shipping_public_config() : [];
    $shippingReady = ($shippingPublic['enabled'] ?? false) === true;
    $shippingEstimateEnabled = ($shippingPublic['estimateEnabled'] ?? true) === true;
    $cdek = function_exists('mahara_shipping_secrets') ? mahara_shipping_secrets() : ['account' => '', 'password' => ''];
    $hasCdek = $cdek['account'] !== '' && $cdek['password'] !== '';
    if ($payments['delivery_mode'] === 'cdek_receiver' && !$shippingReady) $missing[] = $shippingEstimateEnabled
        ? 'Ключи СДЭК, город отправления, тарифы, вес и размеры упакованных вещей'
        : 'API-реквизиты СДЭК и включение выбора пунктов выдачи';
    elseif ($payments['delivery_mode'] === 'unconfigured') $missing[] = 'Настройки доставки';
    if ($payments['enabled'] !== true) $missing[] = 'Включение оплаты после проверки настроек';
    echo json_encode(['bankCredentialsPresent' => $hasBank, 'cdekCredentialsPresent' => $hasCdek,
        'paymentEnabled' => mahara_payments_public_config()['enabled'], 'shippingEnabled' => $shippingReady, 'shippingEstimateEnabled' => $shippingEstimateEnabled,
        'receiptRequestEnabled' => $payments['receipt_enabled'] === true,
        'legalPublished' => $legal['ready'], 'legalMissing' => $legalMissing,
        'mailReady' => mahara_mail_ready(), 'missing' => $missing], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable) {
    fwrite(STDERR, "Не удалось проверить настройки. Проверьте формат приватных файлов конфигурации.\n");
    exit(1);
}
