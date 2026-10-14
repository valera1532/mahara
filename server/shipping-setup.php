<?php
declare(strict_types=1);

if (!defined('MAHARA_SHIPPING_SETUP_INTERNAL') && !defined('MAHARA_LIBRARY_ONLY')) { http_response_code(403); exit; }

function mahara_shipping_setup_library(): void { require_once __DIR__ . '/cdek-setup.php'; }

function mahara_shipping_setup_notice(mixed $code): ?array {
    if (!is_string($code)) return null;
    if ($code === 'saved') return ['type' => 'success', 'code' => $code, 'message' => 'API-реквизиты СДЭК сохранены. Доставка и оплата на сайте не включались.'];
    if ($code === 'checked_authenticated') return ['type' => 'success', 'code' => $code, 'message' => 'СДЭК подтвердил API-реквизиты. Токен не сохранялся; отправления не создавались.'];
    $reason = MaharaCdekSetupCode::tryFrom($code);
    if ($reason === null) return null;
    $message = match ($reason) {
        MaharaCdekSetupCode::AccountEmpty, MaharaCdekSetupCode::AccountInvalid => 'Проверьте Account из раздела интеграции СДЭК.',
        MaharaCdekSetupCode::PasswordEmpty, MaharaCdekSetupCode::PasswordInvalid => 'Введите Secure password из раздела интеграции СДЭК заново.',
        MaharaCdekSetupCode::StorageExists => 'API-реквизиты уже сохранены. Эта форма не заменяет существующий файл; можно выполнить проверку подключения.',
        MaharaCdekSetupCode::PathOverride, MaharaCdekSetupCode::PathInvalid, MaharaCdekSetupCode::PathMissing, MaharaCdekSetupCode::PathPublic => 'Не удалось использовать приватную папку настроек. Требуется проверка конфигурации сервера.',
        MaharaCdekSetupCode::LockUnsafe, MaharaCdekSetupCode::LockFailed, MaharaCdekSetupCode::CreateFailed, MaharaCdekSetupCode::WriteFailed, MaharaCdekSetupCode::PublishFailed => 'Не удалось сохранить API-реквизиты в приватной папке. Требуется проверка прав и файловых функций сервера.',
        MaharaCdekSetupCode::SavedUnavailable, MaharaCdekSetupCode::SavedInvalid => 'Сохранённые API-реквизиты отсутствуют или недоступны для проверки.',
        MaharaCdekSetupCode::Rejected => 'СДЭК не подтвердил API-реквизиты. Для подключения нужны Account и Secure password из раздела интеграции, а не пароль входа в личный кабинет.',
        MaharaCdekSetupCode::CurlUnavailable, MaharaCdekSetupCode::TLSFailed, MaharaCdekSetupCode::ResponseInvalid => 'Не удалось безопасно проверить ответ СДЭК. Сохранённые API-реквизиты не изменились.',
        default => 'Не удалось выполнить действие. API-реквизиты не выводились; доставка на сайте не включалась.',
    };
    return ['type' => 'error', 'code' => $code, 'message' => $message];
}

function mahara_shipping_setup_prepare(string $method, ?array $form = null, ?callable $transport = null): array {
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) throw new MaharaHttpError(405, 'Метод не поддерживается.');
    if (!mahara_secure_request()) throw new MaharaHttpError(403, 'Для настройки СДЭК требуется HTTPS.');
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
        $fields = $action === 'save' ? ['action', 'csrf_token', 'account', 'password'] : ['action', 'csrf_token'];
        if (array_diff(array_keys($form), $fields) || array_filter($form, static fn($value): bool => !is_string($value))) throw new MaharaHttpError(400, 'Некорректная форма.');
        if ($action === 'logout') {
            mahara_query('DELETE FROM sessions WHERE token_hash=?', [$session['token_hash']]);
            mahara_set_cookie('', time() - 3600);
            return ['status' => 303, 'location' => '/admin/login'];
        }
        mahara_shipping_setup_library();
        try {
            if ($action === 'save') {
                $credentials = mahara_cdek_setup_credentials($form['account'] ?? null, $form['password'] ?? null);
                mahara_cdek_setup_save($credentials); unset($credentials); $result = 'saved';
            } else {
                $credentials = mahara_cdek_setup_saved();
                mahara_cdek_setup_check($credentials, $transport); unset($credentials); $result = 'checked_authenticated';
            }
        } catch (Throwable $failure) {
            $result = ($failure instanceof MaharaCdekSetupFailure ? $failure->reason : MaharaCdekSetupCode::Unexpected)->value;
        }
        return ['status' => 303, 'location' => '/admin/shipping-setup?result=' . rawurlencode($result)];
    }
    mahara_shipping_setup_library();
    $notice = mahara_shipping_setup_notice($_GET['result'] ?? null); $present = false;
    try {
        $path = mahara_cdek_setup_private_directory() . '/cdek-secrets.php';
        $present = is_file($path) && !is_link($path);
    } catch (Throwable $failure) {
        $notice = mahara_shipping_setup_notice(($failure instanceof MaharaCdekSetupFailure ? $failure->reason : MaharaCdekSetupCode::Unexpected)->value);
    }
    return ['status' => 200, 'csrfToken' => $session['csrf_token'], 'secretPresent' => $present, 'notice' => $notice];
}

function mahara_shipping_setup_dispatch(string $method): never {
    header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex, nofollow');
    try {
        $response = mahara_shipping_setup_prepare($method); http_response_code($response['status']);
        if (isset($response['location'])) { header('Location: ' . $response['location']); exit; }
        header('Content-Type: text/html; charset=utf-8');
        $csrfToken = $response['csrfToken']; $secretPresent = $response['secretPresent']; $notice = $response['notice'];
        if ($method !== 'HEAD') require __DIR__ . '/templates/admin-shipping-setup.php';
        exit;
    } catch (MaharaHttpError $failure) { mahara_json(['error' => $failure->getMessage()], $failure->status); }
    catch (Throwable) { mahara_json(['error' => 'Не удалось открыть настройки СДЭК.'], 500); }
}
