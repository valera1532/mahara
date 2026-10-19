<?php
declare(strict_types=1);

require_once __DIR__ . '/legal-copy.php';

function mahara_merchant_config(): array {
    $defaults = [
        'published' => false, 'legalVersion' => '',
        'identity' => ['legalName' => '', 'legalForm' => 'ИП', 'registrationNumber' => '', 'taxId' => '', 'address' => '', 'email' => '', 'phone' => ''],
        'commerce' => ['deliveryTerms' => '', 'paymentTerms' => '', 'returnsAddress' => '', 'claimsChannel' => ''],
        'privacy' => [
            'operatorAddress' => '', 'contact' => '',
            'purposes' => 'Оформление и исполнение заказов, связь с покупателем по заказу, учёт оплаты и возвратов, предоставление доступа сотрудникам магазина к управлению сайтом.',
            'dataCategories' => 'Покупатели: имя получателя, телефон, электронная почта, состав и номер заказа, выбранный город и пункт выдачи, сведения о платеже и доставке. Сотрудники: имя пользователя, хеш пароля и сведения о рабочей сессии. Технические журналы сервера могут содержать IP-адрес и сведения о запросах.',
            'processingActions' => 'Сбор через формы сайта, запись, систематизация, хранение, уточнение, использование для исполнения заказа, передача необходимым исполнителям, блокирование и удаление. Обработка автоматизированная с участием сотрудников магазина.',
            'securityMeasures' => 'Доступ сотрудников ограничен авторизацией. Пароли хранятся в виде хешей, административные запросы защищены проверкой источника и токенами. База данных и секреты размещаются вне публичного каталога сайта. В рабочем окружении используется HTTPS.',
            'processors' => '', 'retention' => '', 'deletionProcedure' => '', 'databaseLocation' => '', 'crossBorderTransfers' => '',
        ],
    ];
    $configured = mahara_config()['merchant'] ?? [];
    if (!is_array($configured)) throw new RuntimeException('Invalid merchant settings.');
    return array_replace_recursive($defaults, $configured);
}

function mahara_merchant_legal_status(): array {
    $merchant = mahara_merchant_config();
    $documents = mahara_legal_content($merchant);
    return [
        'ready' => !$documents['terms']['draft'] && !$documents['privacy']['draft'],
        'version' => mahara_legal_value($merchant, 'legalVersion'),
        'termsUrl' => '/terms.html', 'privacyUrl' => '/privacy.html',
    ];
}

function mahara_merchant_missing(): array {
    $documents = mahara_legal_content(mahara_merchant_config());
    $missing = array_unique(array_merge(...array_column($documents, 'missing')));
    $requirements = mahara_legal_requirements();
    return array_map(static fn(string $path): array => ['field' => $path, 'label' => $requirements[$path]['label']], array_values($missing));
}

function mahara_merchant_render(string $path): ?string {
    $key = ['privacy.html' => 'privacy', 'terms.html' => 'terms', 'delivery.html' => 'delivery', 'returns.html' => 'returns', 'contacts.html' => 'contacts'][ltrim($path, '/')] ?? null;
    if (!$key) return null;
    $merchant = mahara_merchant_config();
    $documents = mahara_legal_content($merchant);
    if ($key === 'contacts') {
        $items = [];
        foreach (['legalName' => 'Продавец', 'taxId' => 'ИНН', 'registrationNumber' => 'ОГРН / ОГРНИП', 'address' => 'Адрес', 'email' => 'Электронная почта', 'phone' => 'Телефон'] as $field => $label) {
            $value = mahara_legal_value($merchant, 'identity.' . $field);
            if ($value !== '') $items[] = $label . ': ' . $value;
        }
        $document = ['title' => 'Контакты и реквизиты', 'draft' => $documents['terms']['draft'], 'sections' => [['heading' => 'Продавец MAHARA', 'paragraphs' => [], 'items' => $items]]];
    } else $document = $documents[$key];
    $e = static fn(mixed $text): string => htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $title = $e($document['title']);
    $robots = $document['draft'] ? 'noindex, nofollow' : 'index, follow';
    $body = '';
    foreach ($document['sections'] as $section) {
        $body .= '<section><h2>' . $e($section['heading']) . '</h2>';
        foreach ($section['paragraphs'] as $paragraph) $body .= '<p>' . nl2br($e($paragraph), false) . '</p>';
        if ($section['items']) {
            $body .= '<ul>';
            foreach ($section['items'] as $item) $body .= '<li>' . $e($item) . '</li>';
            $body .= '</ul>';
        }
        $body .= '</section>';
    }
    $version = mahara_legal_value($merchant, 'legalVersion');
    $versionHtml = $version !== '' ? '<p class="legal-version">Версия: ' . $e($version) . '</p>' : '';
    $nav = '';
    foreach (['delivery.html' => 'Доставка и оплата', 'returns.html' => 'Обмен и возврат', 'terms.html' => 'Условия заказа', 'privacy.html' => 'Персональные данные', 'contacts.html' => 'Контакты и реквизиты'] as $file => $label) {
        $nav .= '<a href="/' . $file . '"' . ($path === '/' . $file ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    }
    return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#050505"><meta name="robots" content="' . $robots . '"><title>' . $title . ' | MAHARA</title><link rel="icon" href="/assets/icons/favicon.svg?v=20261019-1" type="image/svg+xml"><link rel="stylesheet" href="/styles.css?v=20261019-1"><link rel="stylesheet" href="/legal.css?v=20261019-1"></head><body><a class="skip-link" href="#main-content">Перейти к содержимому</a><header class="legal-header"><a class="brand" href="/"><img class="brand__logo" src="/assets/icons/mahara-logo-horizontal.svg" alt="MAHARA — Made in Russia" width="190" height="60" /></a><a href="/catalog.html">В каталог →</a></header><main class="legal-main" id="main-content" tabindex="-1"><nav class="legal-nav" aria-label="Информация магазина">' . $nav . '</nav><article class="legal-document"><h1>' . $title . '</h1>' . $versionHtml . $body . '</article></main><footer class="legal-footer">© 2026 MAHARA</footer></body></html>';
}
