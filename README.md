# terms-sdk

HTTP-клиент и контракты шины универсального сервиса [Terms](../terms) (юридические документы, акцепты, пояснения). Пакет `intellect-web-development/terms-sdk`, namespace `IWD\TermsSdk\`.

Чеклист подключения продукта: корневой [`../terms/INTEGRATION.md`](../terms/INTEGRATION.md).

Коды документов и пояснений — произвольные строки `[a-z0-9_]{1,64}`: сервис кодов продукта не знает, список (enum) ведёт сам продукт. Клиент проверяет формат кода до отправки (`InvalidArgumentException`), потому что код попадает в путь запроса.

```php
$client = new TermsClient($httpClient, baseUrl: 'http://backend-terms.localhost', token: $personalJwt);

// Сид документов (идемпотентно по code + version)
$client->createDocument('privacy_policy', version: 1, name: 'Политика', description: null, link: 'https://…');

// Актуальные версии
$client->currentDocuments(['user_agreement', 'privacy_policy']);   // list<TermsDocument>

// Акцепт, отзыв, журнал: адресация по коду, действует актуальная версия
$client->accept(userId: 42, code: 'privacy_policy', userAgent: $ua, ip: $ip);   // AcceptResult|null
$client->revoke(userId: 42, code: 'privacy_policy', userAgent: $ua, ip: $ip);   // RevokeResult|null
$client->history(userId: 42, code: 'privacy_policy');                            // DocumentHistory|null

// Проверка (feature-gate)
$check = $client->checkAcceptance(42, ['user_agreement', 'privacy_policy']);
$check->accepted;   // bool
$check->missing;    // list<MissingDocument>: code, name, reason (document_not_found | not_accepted)
$check->checkInfo;  // list<AcceptanceItem>: code, document, documentAcceptanceStatus|null

// Cron: один вызов вместо цикла по пользователям
$client->checkAcceptanceBatch(userIds: [1, 2, 3], codes: ['user_agreement']);    // array<int, bool>

// Пояснения
$client->acknowledgement(42, 'rebalancing_onboarding', requiredVersion: 1);      // AcknowledgementCheck|null
$client->completeAcknowledgement(42, 'rebalancing_onboarding', version: 1, userAgent: $ua, userIp: $ip);
```

Токен — персональный JWT системы (аудитория `terms`, scopes `terms:documents:{read,write}`, `terms:acknowledgements:{read,write}`), его выдаёт integration-registry. Сервис работает только с данными системы из claim `sub`.

Ошибки: ответы 4xx/5xx и сбои транспорта пробрасываются исключениями Symfony HttpClient как есть. 404 превращается в `null` у `accept`, `revoke`, `history` (нет документа с таким кодом) и `acknowledgement`; у остальных методов 404 остаётся исключением `ClientExceptionInterface`. Пустой набор кодов в `checkAcceptance()` и `checkAcceptanceBatch()` даёт `InvalidArgumentException`; `currentDocuments([])` и `checkAcceptanceBatch([], …)` возвращают пустой результат без запроса.

## HTTP-контракт

Все пути под `/api/sdk/`, заголовок `Authorization: Bearer <JWT>`. Тела — JSON, даты — ISO 8601 (ATOM).

| Метод клиента | HTTP | Тело запроса | Ответ |
|---|---|---|---|
| `createDocument` | `POST /documents` | `code`, `version`, `name`, `description`, `link` | `Document` (201 — создан, 200 — уже был) |
| `currentDocuments` | `GET /documents/current?codes=a,b` | | `{"items": [Document]}`, несуществующие коды пропускаются |
| `accept` | `POST /users/{userId}/documents/{code}/accept` | `userAgent`, `ip` | `{"document", "documentAcceptanceStatus", "alreadyAccepted"}`, 404 — нет документа |
| `revoke` | `POST /users/{userId}/documents/{code}/revoke` | `userAgent`, `ip` | `{"document", "documentAcceptanceStatus"}`, 404 — нет документа |
| `history` | `GET /users/{userId}/documents/{code}/history` | | `{"document", "actions": [Action]}`, 404 — нет документа |
| `checkAcceptance` | `GET /users/{userId}/documents/acceptance?codes=a,b` | | `{"userId", "accepted", "checkInfo": [{"code", "document", "documentAcceptanceStatus"\|null}], "missing": [{"code", "name"\|null, "reason"}]}` |
| `checkAcceptanceBatch` | `POST /documents/acceptance/batch` | `userIds[]`, `codes[]` | `{"items": [{"userId": int, "accepted": bool}]}` |
| `acknowledgement` | `GET /users/{userId}/acknowledgements/{code}[?requiredVersion=N]` | | `{"code", "completed", "version"\|null}` |
| `completeAcknowledgement` | `POST /users/{userId}/acknowledgements/{code}` | `version`, `userAgent`, `userIp` | `Acknowledgement` |

Вложенные формы (поля совпадают с Output-контрактами кабинета bond-screener):

- `Document`: `id` (string), `createdAt`, `updatedAt`, `name`, `description` (string\|null), `link`, `code`, `version` (int).
- `AcceptanceStatus` (`documentAcceptanceStatus`): `id` (string), `createdAt`, `updatedAt`, `userId` (int), `documentId` (int), `status`.
- `Action`: `id` (string), `createdAt`, `updatedAt`, `userId` (int), `datetime`, `type`, `userAgent`, `userIp`.
- `Acknowledgement`: `id` (string), `code`, `version` (int), `completedAt`.

Семантика: акцепт идемпотентен (повтор принятого документа даёт `alreadyAccepted: true`); в `checkInfo` только найденные документы, а код без документа попадает в `missing` с `reason = document_not_found` (гейт остаётся закрытым).

## Enum'ы

| Enum | Значения | Где |
|---|---|---|
| `Status` | `accepted`, `revoked`, `not_accepted` | `DocumentAcceptanceStatus::$status` |
| `ActionType` | `acceptance`, `revocation` | `DocumentAction::$type` |
| `MissingReason` | `document_not_found`, `not_accepted` | `MissingDocument::$reason` |

У каждого есть `states()` — список значений для валидации.

## Шина

Сообщения Messenger (vhost `platform`). Terms событий наружу не публикует, классов `Event` нет.

| Класс | `serializedTypeName` | Exchange |
|-------|----------------------|----------|
| `Message\Command\AcceptDocumentsMessage` | `terms.command.acceptDocuments` | `terms.commands` |

Поля: `userId`, `userAgent`, `ip`, `codes` (list<string>, документы для акцепта актуальных версий), `integrationSystemCode` (APP_SLUG продукта, по нему сервис находит Customer).

Примеры wiring: [`examples/symfony/`](examples/symfony/).

Подключение: vcs-репозиторий в `composer.json` и зависимость `intellect-web-development/terms-sdk`. Path-репозиторий и копия в `packages/` не используются. В `symfony-skeleton` пакет по умолчанию не подключён.
