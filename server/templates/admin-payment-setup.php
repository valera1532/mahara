<?php
declare(strict_types=1);

// The authenticated handler supplies only CSRF, public terminal metadata and safe notices.
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
  <title>Настройка Т-Кассы — MAHARA</title>
  <link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml" />
  <link rel="stylesheet" href="/styles.css?v=20261011-2" />
  <link rel="stylesheet" href="/admin.css?v=20261011-2" />
  <link rel="stylesheet" href="/management.css?v=20261011-2" />
  <style>
    .page-payment-setup .payment-setup-container { max-width: 1080px; }
    .page-payment-setup .cms-intro h1 { font-size: clamp(2.8rem, 5vw, 4.3rem); }
    .page-payment-setup .payment-setup-grid { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, .9fr); gap: 22px; align-items: start; }
    .page-payment-setup .payment-setup-grid > * { min-width: 0; }
    .page-payment-setup .payment-setup-fields { margin: 0; border: 0; }
    .page-payment-setup .payment-setup-fields:disabled input { opacity: .6; }
    .page-payment-setup .payment-setup-notice { padding: 16px 20px; margin-bottom: 22px; border: 1px solid var(--line); border-radius: 12px; background: #111113; }
    .page-payment-setup .payment-setup-notice p { margin: 0; font-size: .8rem; line-height: 1.7; overflow-wrap: anywhere; }
    .page-payment-setup .payment-setup-notice[data-type="error"] { border-color: #69313a; color: #ff8792; }
    .page-payment-setup .payment-setup-notice[data-type="success"] { border-color: #354a3b; color: #b4cbbb; }
    .page-payment-setup .payment-setup-check { padding: 24px 26px; }
    .page-payment-setup .payment-setup-check .cms-save { margin-top: 20px; }
    .page-payment-setup .payment-setup-copy { color: var(--muted); font-size: .76rem; line-height: 1.8; }
    .page-payment-setup .payment-setup-back { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 25px; margin-top: 28px; }
    .page-payment-setup .payment-setup-back a { display: inline-flex; align-items: center; min-height: 44px; font-size: .78rem; }
    @media (max-width: 760px) {
      .page-payment-setup .payment-setup-grid { grid-template-columns: minmax(0, 1fr); gap: 18px; }
      .page-payment-setup .payment-setup-check { padding: 22px 20px; }
    }
  </style>
</head>
<body class="page-admin page-payment-setup">
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
        <form method="post" action="/admin/payment-setup">
          <input type="hidden" name="csrf_token" value="<?= $setupEscape($csrfToken) ?>" />
          <input type="hidden" name="action" value="logout" />
          <button class="management-logout" type="submit">Выйти</button>
        </form>
      </div>
    </div>
  </header>
  <main class="cms-main" id="main-content" tabindex="-1">
    <div class="cms-container payment-setup-container">
      <nav class="breadcrumbs" aria-label="Хлебные крошки"><a href="/admin.html">Управление</a><span aria-hidden="true">/</span><span aria-current="page">Т-Касса</span></nav>
      <section class="cms-intro" aria-labelledby="payment-setup-title">
        <div>
          <p class="eyebrow">MAHARA / Параметры оплаты</p>
          <h1 id="payment-setup-title">Настройка<br><em>Т-Кассы</em></h1>
        </div>
        <div class="cms-intro__copy">
          <p>Сохраните параметры терминала, затем проверьте подключение к банку.</p>
          <div class="cms-publish-note">Сохранение параметров не включает оплату на сайте.</div>
        </div>
      </section>
      <?php if ($setupNoticeMessage !== ''): ?>
      <div class="payment-setup-notice" data-type="<?= $setupEscape($setupNoticeType) ?>" role="<?= $setupNoticeType === 'error' ? 'alert' : 'status' ?>">
        <p><?= $setupEscape($setupNoticeMessage) ?></p>
      </div>
      <?php endif; ?>
      <div class="payment-setup-grid">
        <section class="cms-panel" aria-labelledby="terminal-title">
          <div class="cms-editor__header">
            <span class="cms-kicker">01 / Параметры терминала</span>
            <h2 id="terminal-title">Подключение Т-Кассы</h2>
            <p>Используйте данные из настроек терминала в кабинете Т-Кассы.</p>
          </div>
          <form method="post" action="/admin/payment-setup" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $setupEscape($csrfToken) ?>" />
            <input type="hidden" name="action" value="save" />
            <fieldset class="cms-fields payment-setup-fields"<?= $setupHasSecret ? ' disabled' : '' ?>>
              <legend class="sr-only">Параметры терминала Т-Кассы</legend>
              <div class="cms-field">
                <label for="terminal-key">TerminalKey — идентификатор терминала</label>
                <input id="terminal-key" name="terminal_key" type="text" value="<?= $setupEscape($terminalKey) ?>" autocomplete="off" autocapitalize="none" spellcheck="false" required aria-describedby="terminal-key-hint" />
                <p class="cms-field__hint" id="terminal-key-hint">Публичный идентификатор вашего терминала.</p>
              </div>
              <div class="cms-field">
                <label for="terminal-password">Пароль терминала</label>
                <input id="terminal-password" name="terminal_password" type="password" autocomplete="off" autocapitalize="none" spellcheck="false" required aria-describedby="terminal-password-hint" />
                <p class="cms-field__hint" id="terminal-password-hint">После отправки поле пароля будет пустым. Сохранённый пароль не показывается на странице.</p>
              </div>
            </fieldset>
            <div class="cms-editor__footer">
              <?php if ($setupHasSecret): ?>
              <p class="cms-status">Параметры уже сохранены. На этой странице существующий пароль не заменяется.</p>
              <?php else: ?>
              <p class="cms-status" data-state="dirty">Параметры терминала ещё не сохранены.</p>
              <?php endif; ?>
              <button class="cms-save" type="submit"<?= $setupHasSecret ? ' disabled' : '' ?>><span>Сохранить</span><i aria-hidden="true">↗</i></button>
            </div>
          </form>
        </section>
        <section class="cms-panel" aria-labelledby="bank-check-title">
          <div class="cms-editor__header">
            <span class="cms-kicker">02 / Проверка подключения</span>
            <h2 id="bank-check-title">Ответ банка</h2>
            <p>Проверка обращается к банку без создания платежа.</p>
          </div>
          <div class="payment-setup-check">
            <p class="payment-setup-copy"><?= $setupHasSecret ? 'Параметры сохранены. Можно проверить подключение.' : 'Сначала сохраните параметры терминала. После этого проверка станет доступна.' ?></p>
            <form method="post" action="/admin/payment-setup">
              <input type="hidden" name="csrf_token" value="<?= $setupEscape($csrfToken) ?>" />
              <input type="hidden" name="action" value="check" />
              <button class="cms-save" type="submit"<?= $setupHasSecret ? '' : ' disabled' ?>><span>Проверить подключение</span><i aria-hidden="true">↗</i></button>
            </form>
          </div>
        </section>
      </div>
      <div class="payment-setup-back">
        <a href="/admin.html">← Вернуться в управление</a>
      </div>
    </div>
  </main>
  <footer class="cms-footer"><div class="cms-container payment-setup-container"><span>© 2026 MAHARA</span><span>Ваш бренд. Ваш почерк.</span></div></footer>
</body>
</html>
