<?php
declare(strict_types=1);

if (!defined('MAHARA_PAYMENT_SETUP_INTERNAL') && !defined('MAHARA_LIBRARY_ONLY')) { http_response_code(403); exit; }

function mahara_payment_setup_library(): void {
    if (!defined('MAHARA_TBANK_SETUP_LIBRARY_ONLY')) define('MAHARA_TBANK_SETUP_LIBRARY_ONLY', true);
    require_once dirname(__DIR__) . '/tools/tbank.php';
}

function mahara_payment_setup_notice(mixed $code): ?array {
    if (!is_string($code)) return null;
    if ($code === 'saved') return ['type' => 'success', 'code' => $code, 'message' => 'Реквизиты терминала сохранены. Оплата на сайте не включалась.'];
    if ($code === 'checked_accepted') return ['type' => 'success', 'code' => $code, 'message' => 'Банк принял диагностический запрос. Платёж не создавался; готовность СБП и чеков этой проверкой не подтверждается.'];
    if ($code === 'checked_unknown') return ['type' => 'info', 'code' => $code, 'message' => 'Банк ответил, но результат не подтверждает правильность реквизитов. Платёж не создавался; оплата на сайте не включалась.'];
    $reason = MaharaTbankSetupCode::tryFrom($code);
    if ($reason === null) return null;
    $message = match ($reason) {
        MaharaTbankSetupCode::KeyEmpty, MaharaTbankSetupCode::KeyInvalid => 'Проверьте TerminalKey терминала.',
        MaharaTbankSetupCode::PasswordEmpty => 'Введите новый пароль терминала.',
        MaharaTbankSetupCode::PasswordControls => 'Во вставленном пароле есть управляющие символы. Введите пароль терминала заново.',
        MaharaTbankSetupCode::PasswordInvalid => 'Пароль терминала имеет неподдерживаемый формат. Введите его заново.',
        MaharaTbankSetupCode::StorageExists => 'Реквизиты уже сохранены. Эта форма не заменяет существующий файл; можно выполнить проверку подключения.',
        MaharaTbankSetupCode::PathOverride, MaharaTbankSetupCode::PathInvalid, MaharaTbankSetupCode::PathMissing, MaharaTbankSetupCode::PathPublic => 'Не удалось использовать приватную папку настроек. Требуется проверка конфигурации сервера.',
        MaharaTbankSetupCode::LockUnsafe, MaharaTbankSetupCode::LockFailed, MaharaTbankSetupCode::CreateFailed, MaharaTbankSetupCode::WriteFailed, MaharaTbankSetupCode::PublishFailed => 'Не удалось сохранить реквизиты в приватной папке. Требуется проверка прав и файловых функций сервера.',
        MaharaTbankSetupCode::SavedUnavailable, MaharaTbankSetupCode::SavedInvalid => 'Сохранённые реквизиты отсутствуют или недоступны для проверки.',
        MaharaTbankSetupCode::CurlUnavailable, MaharaTbankSetupCode::InitFailed, MaharaTbankSetupCode::TLSFailed, MaharaTbankSetupCode::ResponseUnverified, MaharaTbankSetupCode::ResponseMalformed, MaharaTbankSetupCode::CAMissing => 'Не удалось безопасно проверить ответ банка. Сохранённые реквизиты не изменились.',
        default => 'Не удалось выполнить действие. Реквизиты не выводились; оплата на сайте не включалась.',
    };
    return ['type' => 'error', 'code' => $code, 'message' => $message];
}

function mahara_payment_setup_prepare(string $method, ?array $form = null, ?callable $transport = null): array {
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) throw new MaharaHttpError(405, 'Метод не поддерживается.');
    if (!mahara_secure_request()) throw new MaharaHttpError(403, 'Для настройки терминала требуется HTTPS.');
    $session = mahara_session();
    if (!$session) {
        if ($method !== 'POST') return ['status' => 302, 'location' => '/admin/login'];
        throw new MaharaHttpError(401, 'Войдите в панель управления.');
    }
    if ($method === 'POST') {
        mahara_require_origin();
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) throw new MaharaHttpError(413, 'Запрос слишком большой.');
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/x-www-form-urlencoded') throw new MaharaHttpError(415, 'Ожидается отправка формы.');
        if ($form === null) { parse_str(mahara_read_body(8192), $form); }
        $token = $form['csrf_token'] ?? null;
        if (!is_string($token) || !hash_equals($session['csrf_token'], $token)) throw new MaharaHttpError(403, 'Обновите страницу и повторите действие.');
        $action = $form['action'] ?? null;
        if (!is_string($action) || !in_array($action, ['save', 'check', 'logout'], true)) throw new MaharaHttpError(400, 'Некорректное действие.');
        $fields = $action === 'save' ? ['action', 'csrf_token', 'terminal_key', 'terminal_password'] : ['action', 'csrf_token'];
        if (array_diff(array_keys($form), $fields) || array_filter($form, static fn($value): bool => !is_string($value))) throw new MaharaHttpError(400, 'Некорректная форма.');
        if ($action === 'logout') {
            mahara_query('DELETE FROM sessions WHERE token_hash=?', [$session['token_hash']]);
            mahara_set_cookie('', time() - 3600);
            return ['status' => 303, 'location' => '/admin/login'];
        }
        // Only an authenticated HTTPS POST with the same origin and a valid
        // form CSRF token may load the credential library or read stored secrets.
        mahara_payment_setup_library();
        try {
            if ($action === 'save') {
                $credentials = mahara_tbank_setup_credentials($form['terminal_key'] ?? null, $form['terminal_password'] ?? null);
                mahara_tbank_setup_save($credentials); unset($credentials);
                $result = 'saved';
            } else {
                $credentials = mahara_tbank_setup_saved();
                $check = mahara_tbank_setup_check($credentials, $transport); unset($credentials);
                $result = $check['credentialDiagnostics'] === 'accepted' ? 'checked_accepted' : 'checked_unknown';
            }
        } catch (Throwable $failure) {
            $result = ($failure instanceof MaharaTbankSetupFailure ? $failure->reason : MaharaTbankSetupCode::Unexpected)->value;
        }
        // Only a closed status code goes into the redirect. No request fields,
        // password, bank response, PHP flash/session data or raw errors are used.
        return ['status' => 303, 'location' => '/admin/payment-setup?result=' . rawurlencode($result)];
    }
    mahara_payment_setup_library();
    $notice = mahara_payment_setup_notice($_GET['result'] ?? null); $present = false;
    try {
        $path = mahara_tbank_setup_private_directory() . '/tbank-secrets.php';
        $present = is_file($path) && !is_link($path);
    } catch (Throwable $failure) {
        $notice = mahara_payment_setup_notice(($failure instanceof MaharaTbankSetupFailure ? $failure->reason : MaharaTbankSetupCode::Unexpected)->value);
    }
    return ['status' => 200, 'csrfToken' => $session['csrf_token'], 'terminalKey' => '1787834059773', 'secretPresent' => $present, 'notice' => $notice];
}

function mahara_payment_setup_dispatch(string $method): never {
    header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex, nofollow');
    try {
        $response = mahara_payment_setup_prepare($method);
        http_response_code($response['status']);
        if (isset($response['location'])) { header('Location: ' . $response['location']); exit; }
        header('Content-Type: text/html; charset=utf-8');
        $csrfToken = $response['csrfToken']; $terminalKey = $response['terminalKey'];
        $secretPresent = $response['secretPresent']; $notice = $response['notice'];
        if ($method !== 'HEAD') require __DIR__ . '/templates/admin-payment-setup.php';
        exit;
    } catch (MaharaHttpError $failure) { mahara_json(['error' => $failure->getMessage()], $failure->status); }
    catch (Throwable) { mahara_json(['error' => 'Не удалось открыть настройки терминала.'], 500); }
}
