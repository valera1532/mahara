<?php
declare(strict_types=1);

/**
 * Plain Russian legal page drafts. The caller validates merchant settings and
 * escapes every string for its output context. This file does not read config,
 * persist data, contact services, render HTML, or enable checkout.
 *
 * Primary sources checked 2026-10-11:
 * 152-FZ, arts. 5, 6, 9, 15, 18, 18.1, 21, 22:
 * https://mintrud.gov.ru/docs/laws/130
 * Separate consent since 2025-09-01:
 * https://epp.genproc.gov.ru/ru/proc_38/activity/legal-education/explain/otherwise/e5978821/
 * Seller disclosure and claims, Rules 657 pp.5,22,24 (since 2026-09-01):
 * https://publication.pravo.gov.ru/document/0001202606010077
 * https://zpp.rospotrebnadzor.ru/news/federal/593809
 * Distance-sale returns, including the 2026 return-method clarification:
 * https://zpp.rospotrebnadzor.ru/news/federal/574244
 * https://zpp.rospotrebnadzor.ru/news/federal/572363
 * Online fiscal receipt requirements:
 * https://www.nalog.gov.ru/rn75/news/activities_fts/16545509/
 * The owner must review these drafts against actual business processes before
 * setting published=true. Presence of text alone is not legal compliance.
 */

/** Dotted setting paths with labels for configuration and readiness UI. */
function mahara_legal_requirements(): array
{
    $all = ['privacy', 'terms', 'delivery', 'returns'];
    return [
        'published' => ['label' => 'Подтверждение владельцем публикации документов', 'documents' => $all, 'required' => true],
        'legalVersion' => ['label' => 'Версия правовых документов', 'documents' => $all, 'required' => true],
        'identity.legalName' => ['label' => 'Полное наименование продавца или ФИО ИП', 'documents' => $all, 'required' => true],
        'identity.legalForm' => ['label' => 'Правовой статус продавца', 'documents' => $all, 'required' => true],
        'identity.registrationNumber' => ['label' => 'ОГРН или ОГРНИП', 'documents' => $all, 'required' => true],
        'identity.taxId' => ['label' => 'ИНН', 'documents' => $all, 'required' => false],
        'identity.address' => ['label' => 'Адрес и место нахождения продавца', 'documents' => $all, 'required' => true],
        // Rule 657 p.24 requires a claims email for both an IP and a legal entity.
        // The optional phone under p.22 does not replace that email.
        'identity.email' => ['label' => 'Электронная почта продавца для обращений и претензий', 'documents' => $all, 'required' => true],
        'identity.phone' => ['label' => 'Телефон продавца', 'documents' => $all, 'required' => false],
        'commerce.deliveryTerms' => ['label' => 'География, способы, сроки и стоимость доставки', 'documents' => ['terms', 'delivery'], 'required' => true],
        'commerce.paymentTerms' => ['label' => 'Способы и порядок оплаты товара и доставки', 'documents' => ['terms', 'delivery'], 'required' => true],
        'commerce.returnsAddress' => ['label' => 'Адрес для возврата товара', 'documents' => ['terms', 'returns'], 'required' => true],
        'commerce.claimsChannel' => ['label' => 'Способы направления претензий и заявлений о возврате', 'documents' => ['terms', 'returns'], 'required' => true],
        // These two fields fall back to the seller's address and contact.
        'privacy.operatorAddress' => ['label' => 'Адрес оператора персональных данных', 'documents' => ['privacy'], 'required' => true],
        'privacy.contact' => ['label' => 'Контакт для обращений о персональных данных', 'documents' => ['privacy'], 'required' => true],
        'privacy.purposes' => ['label' => 'Фактические цели обработки персональных данных', 'documents' => ['privacy'], 'required' => true],
        'privacy.dataCategories' => ['label' => 'Состав обрабатываемых данных и категории субъектов', 'documents' => ['privacy'], 'required' => true],
        'privacy.processingActions' => ['label' => 'Действия с данными и способы обработки', 'documents' => ['privacy'], 'required' => true],
        'privacy.processors' => ['label' => 'Получатели данных, обработчики и цели передачи', 'documents' => ['privacy'], 'required' => true],
        'privacy.retention' => ['label' => 'Сроки или условия хранения по каждой цели', 'documents' => ['privacy'], 'required' => true],
        'privacy.deletionProcedure' => ['label' => 'Порядок прекращения обработки и удаления данных', 'documents' => ['privacy'], 'required' => true],
        'privacy.databaseLocation' => ['label' => 'Размещение баз данных и резервных копий', 'documents' => ['privacy'], 'required' => true],
        'privacy.crossBorderTransfers' => ['label' => 'Наличие и условия трансграничной передачи', 'documents' => ['privacy'], 'required' => true],
        'privacy.securityMeasures' => ['label' => 'Общедоступное описание мер защиты данных', 'documents' => ['privacy'], 'required' => true],
    ];
}

/** Read a plain string with the documented contact/address fallbacks. */
function mahara_legal_value(array $merchant, string $path): string
{
    $value = $merchant;
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) { $value = ''; break; }
        $value = $value[$key];
    }
    $text = is_string($value) ? trim($value) : '';
    if ($text !== '') return $text;
    if ($path === 'privacy.operatorAddress') return mahara_legal_value($merchant, 'identity.address');
    if ($path === 'privacy.contact') return mahara_legal_value($merchant, 'identity.email');
    return '';
}

/**
 * @return array<string,array{title:string,draft:bool,missing:array,sections:array}>
 * `missing` contains dotted requirement keys. `draft` also remains true until
 * the owner explicitly publishes a version. No settings are inferred from the
 * brand name, a social profile, source code, or a service's default terms.
 */
function mahara_legal_content(array $merchant): array
{
    $requirements = mahara_legal_requirements();
    $value = static function (string $path) use ($merchant, $requirements): string {
        $text = mahara_legal_value($merchant, $path);
        return $text !== '' ? $text : 'Не заполнено: ' . ($requirements[$path]['label'] ?? $path);
    };
    $section = static fn(string $heading, array $paragraphs = [], array $items = []): array => [
        'heading' => $heading, 'paragraphs' => $paragraphs, 'items' => $items,
    ];
    $identity = [
        'Продавец: ' . $value('identity.legalName') . '.',
        'Правовой статус: ' . $value('identity.legalForm') . '.',
        'ОГРН / ОГРНИП: ' . $value('identity.registrationNumber') . '.',
        'Адрес: ' . $value('identity.address') . '.',
    ];
    foreach (['identity.taxId' => 'ИНН', 'identity.email' => 'Контакт', 'identity.phone' => 'Телефон'] as $path => $label) {
        $text = mahara_legal_value($merchant, $path);
        if ($text !== '') $identity[] = $label . ': ' . $text . '.';
    }
    $returnsRights = $section('Отказ от товара надлежащего качества', [
        'От заказа можно отказаться до передачи товара. После получения товара, купленного дистанционно, покупатель вправе отказаться от него в течение семи дней.',
        'Если при доставке не передана письменная информация о порядке и сроках возврата, срок отказа составляет три месяца со дня получения товара.',
        'Для возврата должны быть сохранены товарный вид и потребительские свойства. Отсутствие чека не исключает другие доказательства покупки. Исключение касается товара с индивидуально определёнными свойствами, который может использовать только купивший его покупатель.',
    ]);
    $returnMethod = $section('Как направить возврат или претензию', [
        $value('commerce.claimsChannel'),
        'Адрес для возврата: ' . $value('commerce.returnsAddress') . '.',
        'Покупатель может выбрать способ возврата, в том числе перевозчика или почтовую отправку, если при получении продавец сможет проверить состояние товара. При возврате товара надлежащего качества покупатель несёт связанные с обратной пересылкой расходы и риск случайной утраты или повреждения в пути.',
        'Для обращения укажите данные заказа, требование и способ связи. Фото или описание недостатка помогут рассмотреть претензию, но не заменяют и не ограничивают права, предусмотренные законом.',
    ]);
    $refund = $section('Возврат денег и товары с недостатками', [
        'При отказе от товара надлежащего качества продавец возвращает уплаченную сумму не позднее десяти дней со дня предъявления требования. Из неё могут быть исключены расходы продавца на доставку возвращённого товара от покупателя.',
        'Если обнаружен недостаток, покупатель вправе предъявить предусмотренные законом требования, в том числе о замене, устранении недостатка, уменьшении цены или возврате денег. Выбор требования, сроки и расходы определяются законодательством с учётом обстоятельств покупки.',
        'Эти условия не ограничивают предусмотренные законодательством права потребителя.',
    ]);
    $documents = [
        'privacy' => [
            'title' => 'Политика обработки персональных данных',
            'sections' => [
                $section('Оператор и обращения', [
                    'Оператор персональных данных: ' . $value('identity.legalName') . '.',
                    'Адрес оператора: ' . $value('privacy.operatorAddress') . '.',
                    'Контакт для вопросов и запросов: ' . $value('privacy.contact') . '.',
                    'Политика описывает обработку данных при использовании сайта и оформлении заказов. Версия документа: ' . $value('legalVersion') . '.',
                ]),
                $section('Цели, состав и способы обработки', [
                    $value('privacy.purposes'), $value('privacy.dataCategories'), $value('privacy.processingActions'),
                ]),
                $section('Правовые основания', [
                    'Данные, необходимые для заключения договора по инициативе покупателя и исполнения его заказа, обрабатываются на основании пункта 5 части 1 статьи 6 Федерального закона № 152-ФЗ. Данные для исполнения обязанностей, установленных законом, обрабатываются на соответствующем законном основании.',
                    'Для каждой цели применяется соответствующее правовое основание. Если для отдельной цели нужно согласие, оно оформляется отдельно от принятия условий заказа и других подтверждаемых документов.',
                    'Рекламные сообщения требуют отдельного предварительного разрешения. Согласие на рекламу добровольно и не является условием покупки. Его можно отозвать; отзыв не прекращает обработку, которая необходима для исполнения заказа или требований закона.',
                ]),
                $section('Получатели данных и размещение баз', [
                    $value('privacy.processors'), $value('privacy.databaseLocation'), $value('privacy.crossBorderTransfers'),
                ]),
                $section('Сроки хранения и прекращение обработки', [
                    $value('privacy.retention'), $value('privacy.deletionProcedure'),
                    'Объём и сроки обработки ограничиваются указанными целями и обязательными требованиями закона. Обработка для другой самостоятельной цели требует отдельного основания.',
                ]),
                $section('Защита данных', [$value('privacy.securityMeasures')]),
                $section('Хранение в браузере', [
                    'Корзина и избранное сохраняются в локальном хранилище вашего браузера. Они могут сохраняться после его закрытия и не синхронизируются между разными устройствами. Их можно удалить средствами браузера.',
                    'Доступ к оформленному заказу сохраняется в хранилище исходной вкладки. При доступном восстановлении по почте можно запросить новую одноразовую ссылку. Не передавайте ссылки доступа к заказу другим людям.',
                    'Для авторизованной работы сотрудников используется cookie сессии. Рекламные и аналитические трекеры в текущем сайте не подключены. Если состав сервисов изменится, политика подлежит обновлению.',
                ]),
                $section('Права человека', [
                    'Можно запросить сведения об обработке своих данных, потребовать уточнения неточных данных, а в предусмотренных законом случаях — блокирования, прекращения обработки или удаления. Для обращения используйте контакт оператора выше.',
                    'Если обработка основана на согласии, его можно отозвать. При наличии другого законного основания оператор может продолжить необходимую обработку. Действия оператора можно обжаловать в Роскомнадзор или суд.',
                ]),
            ],
        ],
        'terms' => [
            'title' => 'Условия заказа',
            'sections' => [
                $section('Продавец', [], $identity),
                $section('Оформление заказа', [
                    'Покупатель выбирает товар и размер, проверяет состав заказа и стоимость, указывает достоверные контактные данные и сведения для выбранного способа доставки.',
                    'Цена товара указывается в рублях. Сведения о товаре, итоговой стоимости, оплате и доставке должны быть доступны до оформления заказа. Заключение и исполнение договора регулируются законодательством Российской Федерации о розничной купле-продаже и защите прав потребителей.',
                ]),
                $section('Оплата', [$value('commerce.paymentTerms')]),
                $section('Доставка', [$value('commerce.deliveryTerms')]),
                $returnMethod, $returnsRights, $refund,
                $section('Персональные данные', [
                    'Обработка данных для оформления и исполнения заказа описана в политике обработки персональных данных. Принятие условий заказа не означает согласия на рекламную рассылку.',
                ]),
            ],
        ],
        'delivery' => [
            'title' => 'Доставка и оплата',
            'sections' => [
                $section('Продавец и контакт', [], $identity),
                $section('Доставка', [$value('commerce.deliveryTerms')]),
                $section('Оплата', [$value('commerce.paymentTerms')]),
                $section('Перед оплатой', [
                    'Проверьте состав заказа, размер, контактные данные, получателя и выбранное место доставки. Стоимость товара и условия оплаты доставки сообщаются до оформления заказа.',
                    'Кассовый чек предоставляется в порядке, предусмотренном законодательством. Подтверждение банковского платежа и кассовый чек выполняют разные функции.',
                ]),
            ],
        ],
        'returns' => [
            'title' => 'Обмен и возврат',
            'sections' => [$section('Продавец', [], $identity), $returnsRights, $returnMethod, $refund],
        ],
    ];
    foreach ($documents as $name => &$document) {
        $missing = [];
        foreach ($requirements as $path => $requirement) {
            if (!$requirement['required'] || !in_array($name, $requirement['documents'], true)) continue;
            $present = $path === 'published' ? ($merchant['published'] ?? false) === true : mahara_legal_value($merchant, $path) !== '';
            if (!$present) $missing[] = $path;
        }
        $document = ['title' => $document['title'], 'draft' => $missing !== [], 'missing' => $missing, 'sections' => $document['sections']];
    }
    unset($document);
    return $documents;
}
