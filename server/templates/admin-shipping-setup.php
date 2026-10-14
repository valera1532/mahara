<?php
declare(strict_types=1);

// The authenticated handler supplies only CSRF, credential presence and safe notices.
$setupEscape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$setupHasSecret = ($secretPresent ?? false) === true;
$setupNoticeType = is_array($notice ?? null) && in_array($notice['type'] ?? '', ['success', 'error', 'info'], true) ? $notice['type'] : 'info';
$setupNoticeMessage = is_array($notice ?? null) && is_string($notice['message'] ?? null) ? $notice['message'] : '';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="theme-color" content="#050505" />
  <meta name="robots" content="noindex, nofollow" />
  <title>Настройка СДЭК — MAHARA</title>
  <link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml" />
  <link rel="stylesheet" href="/styles.css?v=20261011-2" />
  <link rel="stylesheet" href="/admin.css?v=20261011-2" />
  <link rel="stylesheet" href="/management.css?v=20261011-2" />
  <style>
    .page-shipping-setup .shipping-setup-container { max-width: 1080px; }
    .page-shipping-setup .cms-intro h1 { font-size: clamp(2.8rem, 5vw, 4.3rem); }
    .page-shipping-setup .shipping-setup-grid { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, .9fr); gap: 22px; align-items: start; }
    .page-shipping-setup .shipping-setup-grid > * { min-width: 0; }
    .page-shipping-setup .shipping-setup-fields { margin: 0; border: 0; }
    .page-shipping-setup .shipping-setup-fields:disabled input { opacity: .6; }
    .page-shipping-setup .shipping-setup-notice { padding: 16px 20px; margin-bottom: 22px; border: 1px solid var(--line); border-radius: 12px; background: #111113; }
    .page-shipping-setup .shipping-setup-notice p { margin: 0; font-size: .8rem; line-height: 1.7; overflow-wrap: anywhere; }
    .page-shipping-setup .shipping-setup-notice[data-type="error"] { border-color: #69313a; color: #ff8792; }
    .page-shipping-setup .shipping-setup-notice[data-type="success"] { border-color: #354a3b; color: #b4cbbb; }
    .page-shipping-setup .shipping-setup-check { padding: 24px 26px; }
    .page-shipping-setup .shipping-setup-check .cms-save { margin-top: 20px; }
    .page-shipping-setup .shipping-setup-copy { color: var(--muted); font-size: .76rem; line-height: 1.8; }
    .page-shipping-setup .shipping-setup-back { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 25px; margin-top: 28px; }
    .page-shipping-setup .shipping-setup-back a { display: inline-flex; align-items: center; min-height: 44px; font-size: .78rem; }
    @media (max-width: 760px) {
      .page-shipping-setup .shipping-setup-grid { grid-template-columns: minmax(0, 1fr); gap: 18px; }
      .page-shipping-setup .shipping-setup-check { padding: 22px 20px; }
    }
  </style>
</head>
<body class="page-admin page-shipping-setup">
  <a class="skip-link" href="#main-content">Перейти к содержимому</a>
  <div class="site-noise" aria-hidden="true"></div>
  <header class="management-header cms-topbar">
    <div class="management-header__inner">
      <a class="brand" href="/admin.html" aria-label="MAHARA — управление">
        <svg class="brand__icon" viewBox="0 0 64 64" aria-hidden="true"><path d="M14 50V32l8-8 5 5V17l5-7 5 7v12l5-5 8 8v18M8 50h48M22 50V35M32 50V21M42 50V35" /></svg>
        <span>MAHARA</span>
      </a>
      <span class="management-header__label">Управление</span>
      <nav class="management-nav" aria-label="Разделы управления">
        <a href="/orders.html">Заказы</a>
        <a href="/admin.html">Контент и товары</a>
        <a href="/inventory.html">Склад</a>
        <a href="/catalog.html">Витрина <span aria-hidden="true">↗</span></a>
      </nav>
      <div class="management-header__actions">
        <form method="post" action="/admin/shipping-setup">
          <input type="hidden" name="csrf_token" value="<?= $setupEscape($csrfToken) ?>" />
          <input type="hidden" name="action" value="logout" />
          <button class="management-logout" type="submit">Выйти</button>
        </form>
      </div>
    </div>
  </header>
  <main class="cms-main" id="main-content" tabindex="-1">
    <div class="cms-container shipping-setup-container">
      <nav class="breadcrumbs" aria-label="Хлебные крошки"><a href="/admin.html">Управление</a><span aria-hidden="true">/</span><span aria-current="page">СДЭК</span></nav>
      <section class="cms-intro" aria-labelledby="shipping-setup-title">
        <div>
          <p class="eyebrow">MAHARA / Доставка СДЭК</p>
          <h1 id="shipping-setup-title">Настройка<br><em>СДЭК</em></h1>
        </div>
        <div class="cms-intro__copy">
          <p>Сохраните ключи API СДЭК, затем проверьте доступ к сервису.</p>
          <div class="cms-publish-note">Доставку СДЭК оплачивает получатель отдельно.</div>
        </div>
      </section>
      <?php if ($setupNoticeMessage !== ''): ?>
      <div class="shipping-setup-notice" data-type="<?= $setupEscape($setupNoticeType) ?>" role="<?= $setupNoticeType === 'error' ? 'alert' : 'status' ?>">
        <p><?= $setupEscape($setupNoticeMessage) ?></p>
      </div>
      <?php endif; ?>
      <div class="shipping-setup-grid">
        <section class="cms-panel" aria-labelledby="credentials-title">
          <div class="cms-editor__header">
            <span class="cms-kicker">01 / Ключи API</span>
            <h2 id="credentials-title">Данные интеграции</h2>
            <p>Получите ключи в <a href="https://lk.cdek.ru/integration" target="_blank" rel="noopener noreferrer">разделе интеграции СДЭК <span aria-hidden="true">↗</span></a>. Логин и пароль от личного кабинета не подходят.</p>
          </div>
          <form method="post" action="/admin/shipping-setup" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $setupEscape($csrfToken) ?>" />
            <input type="hidden" name="action" value="save" />
            <fieldset class="cms-fields shipping-setup-fields"<?= $setupHasSecret ? ' disabled' : '' ?>>
              <legend class="sr-only">Ключи API СДЭК</legend>
              <div class="cms-field">
                <label for="cdek-account">Account (ключ API)</label>
                <input id="cdek-account" name="account" type="text" autocomplete="off" autocapitalize="none" spellcheck="false" required aria-describedby="cdek-account-hint" />
                <p class="cms-field__hint" id="cdek-account-hint">Account из раздела интеграции СДЭК.</p>
              </div>
              <div class="cms-field">
                <label for="cdek-password">Secure password (пароль API)</label>
                <?php // The CDEK API secret is separate from saved site credentials; avoid stored-password autofill. ?>
                <input id="cdek-password" name="password" type="password" autocomplete="new-password" autocapitalize="none" spellcheck="false" required aria-describedby="cdek-password-hint" />
                <p class="cms-field__hint" id="cdek-password-hint">После отправки поле пароля будет пустым. Сохранённый пароль не показывается на странице.</p>
              </div>
            </fieldset>
            <div class="cms-editor__footer">
              <?php if ($setupHasSecret): ?>
              <p class="cms-status">Ключи API уже сохранены. На этой странице они не заменяются.</p>
              <?php else: ?>
              <p class="cms-status" data-state="dirty">Ключи API ещё не сохранены.</p>
              <?php endif; ?>
              <button class="cms-save" type="submit"<?= $setupHasSecret ? ' disabled' : '' ?>><span>Сохранить</span><i aria-hidden="true">↗</i></button>
            </div>
          </form>
        </section>
        <section class="cms-panel" aria-labelledby="shipping-check-title">
          <div class="cms-editor__header">
            <span class="cms-kicker">02 / Проверка доступа</span>
            <h2 id="shipping-check-title">Доступ к СДЭК</h2>
            <p>Проверяется только доступ к API СДЭК. Заказы и отправления не создаются.</p>
          </div>
          <div class="shipping-setup-check">
            <p class="shipping-setup-copy"><?= $setupHasSecret ? 'Ключи сохранены. Можно проверить доступ к API.' : 'Сначала сохраните ключи API. После этого проверка станет доступна.' ?></p>
            <p class="shipping-setup-copy">Успешная проверка доступа ещё не означает готовность оформления и доставки заказов.</p>
            <form method="post" action="/admin/shipping-setup">
              <input type="hidden" name="csrf_token" value="<?= $setupEscape($csrfToken) ?>" />
              <input type="hidden" name="action" value="check" />
              <button class="cms-save" type="submit"<?= $setupHasSecret ? '' : ' disabled' ?>><span>Проверить доступ</span><i aria-hidden="true">↗</i></button>
            </form>
          </div>
        </section>
      </div>
      <div class="shipping-setup-back">
        <a href="/admin.html">← Вернуться в управление</a>
      </div>
    </div>
  </main>
  <footer class="cms-footer"><div class="cms-container shipping-setup-container"><span>© 2026 MAHARA</span><span>Ваш бренд. Ваш почерк.</span></div></footer>
</body>
</html>
