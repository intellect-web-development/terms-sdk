<?php

declare(strict_types=1);

namespace IWD\TermsSdk;

use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Клиент универсального сервиса Terms (юридические документы, акцепты, пояснения).
 *
 * Токен — персональный JWT системы (выдаёт integration-registry): сервис берёт код системы из claim `sub`
 * и работает только с данными этой системы. Коды документов и пояснений — строки `[a-z0-9_]{1,64}`, их список
 * ведёт продукт, сервис кодов не знает.
 *
 * Ошибки транспорта и ответы 401/403/5xx пробрасываются исключениями Symfony HttpClient, обработка — на стороне
 * вызывающего (адаптер продукта). 404 превращается в null только там, где это сказано в описании метода.
 */
final readonly class TermsClient
{
    private const string CODE_PATTERN = '/^[a-z0-9_]{1,64}$/';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $token,
    ) {
    }

    /**
     * Заводит версию документа. Идемпотентно по (code, version): повтор возвращает существующий документ без изменений.
     */
    public function createDocument(
        string $code,
        int $version,
        string $name,
        ?string $description,
        string $link,
    ): TermsDocument {
        $this->assertCode($code);

        $data = $this->request('POST', '/documents', [
            'json' => [
                'code' => $code,
                'version' => $version,
                'name' => $name,
                'description' => $description,
                'link' => $link,
            ],
        ]);

        return $this->mapDocument($data);
    }

    /**
     * Актуальные (с максимальной версией) документы по кодам. Коды без документа в ответ не попадают.
     *
     * @param list<string> $codes
     *
     * @return list<TermsDocument>
     */
    public function currentDocuments(array $codes): array
    {
        if ([] === $codes) {
            return [];
        }

        $data = $this->request('GET', '/documents/current', [
            'query' => ['codes' => $this->joinCodes($codes)],
        ]);

        return array_map($this->mapDocument(...), array_values($data['items']));
    }

    /**
     * Принимает актуальную версию документа с этим кодом. Повторный акцепт ничего не меняет (`alreadyAccepted`).
     * null, если документа с таким кодом нет в сервисе (404).
     */
    public function accept(int $userId, string $code, string $userAgent, string $ip): ?AcceptResult
    {
        $data = $this->requestOrNull('POST', $this->documentPath($userId, $code) . '/accept', [
            'json' => ['userAgent' => $userAgent, 'ip' => $ip],
        ]);

        if (null === $data) {
            return null;
        }

        return new AcceptResult(
            document: $this->mapDocument($data['document']),
            documentAcceptanceStatus: $this->mapStatus($data['documentAcceptanceStatus']),
            alreadyAccepted: (bool) $data['alreadyAccepted'],
        );
    }

    /**
     * Отзывает акцепт актуальной версии документа с этим кодом.
     * null, если документа с таким кодом нет в сервисе (404).
     */
    public function revoke(int $userId, string $code, string $userAgent, string $ip): ?RevokeResult
    {
        $data = $this->requestOrNull('POST', $this->documentPath($userId, $code) . '/revoke', [
            'json' => ['userAgent' => $userAgent, 'ip' => $ip],
        ]);

        if (null === $data) {
            return null;
        }

        return new RevokeResult(
            document: $this->mapDocument($data['document']),
            documentAcceptanceStatus: $this->mapStatus($data['documentAcceptanceStatus']),
        );
    }

    /**
     * Журнал акцептов и отзывов пользователя по актуальной версии документа.
     * null, если документа с таким кодом нет в сервисе (404).
     */
    public function history(int $userId, string $code): ?DocumentHistory
    {
        $data = $this->requestOrNull('GET', $this->documentPath($userId, $code) . '/history');

        if (null === $data) {
            return null;
        }

        return new DocumentHistory(
            document: $this->mapDocument($data['document']),
            actions: array_map($this->mapAction(...), array_values($data['actions'])),
        );
    }

    /**
     * Принята ли пользователем актуальная версия каждого документа из набора.
     * Код без заведённого документа попадает в `missing` с причиной document_not_found: гейт остаётся закрытым.
     *
     * @param list<string> $codes
     *
     * @throws InvalidArgumentException если набор кодов пуст или код не соответствует `[a-z0-9_]{1,64}`
     */
    public function checkAcceptance(int $userId, array $codes): AcceptanceCheck
    {
        $data = $this->request('GET', '/users/' . $userId . '/documents/acceptance', [
            'query' => ['codes' => $this->joinCodes($codes)],
        ]);

        return new AcceptanceCheck(
            userId: (int) $data['userId'],
            accepted: (bool) $data['accepted'],
            checkInfo: array_map($this->mapItem(...), array_values($data['checkInfo'])),
            missing: array_map(
                static fn (array $row): MissingDocument => new MissingDocument(
                    code: (string) $row['code'],
                    name: isset($row['name']) ? (string) $row['name'] : null,
                    reason: MissingReason::from((string) $row['reason']),
                ),
                array_values($data['missing']),
            ),
        );
    }

    /**
     * Пакетная проверка для cron: один вызов вместо цикла по пользователям.
     *
     * @param list<int>    $userIds
     * @param list<string> $codes
     *
     * @return array<int, bool> userId => приняты ли все документы набора
     *
     * @throws InvalidArgumentException если набор кодов пуст или код не соответствует `[a-z0-9_]{1,64}`
     */
    public function checkAcceptanceBatch(array $userIds, array $codes): array
    {
        $this->joinCodes($codes);
        if ([] === $userIds) {
            return [];
        }

        $data = $this->request('POST', '/documents/acceptance/batch', [
            'json' => [
                'userIds' => array_values($userIds),
                'codes' => array_values($codes),
            ],
        ]);

        $result = [];
        foreach ($data['items'] as $row) {
            $result[(int) $row['userId']] = (bool) $row['accepted'];
        }

        return $result;
    }

    /**
     * Пройдено ли пользователем пояснение.
     *
     * @param int|null $requiredVersion Требуемая версия; null — сервис берёт версию по умолчанию
     *
     * @return AcknowledgementCheck|null null, если сервис ответил 404
     */
    public function acknowledgement(int $userId, string $code, ?int $requiredVersion = null): ?AcknowledgementCheck
    {
        $this->assertCode($code);

        $options = [];
        if (null !== $requiredVersion) {
            $options['query'] = ['requiredVersion' => $requiredVersion];
        }

        $data = $this->requestOrNull('GET', $this->acknowledgementPath($userId, $code), $options);

        if (null === $data) {
            return null;
        }

        return new AcknowledgementCheck(
            code: (string) $data['code'],
            completed: (bool) $data['completed'],
            version: isset($data['version']) ? (int) $data['version'] : null,
        );
    }

    /** Фиксирует прохождение пояснения указанной версии */
    public function completeAcknowledgement(
        int $userId,
        string $code,
        int $version,
        string $userAgent,
        string $userIp,
    ): UserAcknowledgement {
        $this->assertCode($code);

        $data = $this->request('POST', $this->acknowledgementPath($userId, $code), [
            'json' => ['version' => $version, 'userAgent' => $userAgent, 'userIp' => $userIp],
        ]);

        return new UserAcknowledgement(
            id: (string) $data['id'],
            code: (string) $data['code'],
            version: (int) $data['version'],
            completedAt: new DateTimeImmutable((string) $data['completedAt']),
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $options['headers'] = ['Authorization' => 'Bearer ' . $this->token];

        return $this->httpClient
            ->request($method, rtrim($this->baseUrl, '/') . '/api/sdk' . $path, $options)
            ->toArray();
    }

    /**
     * То же, что request(), но 404 даёт null.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|null
     */
    private function requestOrNull(string $method, string $path, array $options = []): ?array
    {
        try {
            return $this->request($method, $path, $options);
        } catch (ClientExceptionInterface $exception) {
            if (404 === $exception->getResponse()->getStatusCode()) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapDocument(array $data): TermsDocument
    {
        return new TermsDocument(
            id: (string) $data['id'],
            createdAt: new DateTimeImmutable((string) $data['createdAt']),
            updatedAt: new DateTimeImmutable((string) $data['updatedAt']),
            name: (string) $data['name'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            link: (string) $data['link'],
            code: (string) $data['code'],
            version: (int) $data['version'],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapStatus(array $data): DocumentAcceptanceStatus
    {
        return new DocumentAcceptanceStatus(
            id: (string) $data['id'],
            createdAt: new DateTimeImmutable((string) $data['createdAt']),
            updatedAt: new DateTimeImmutable((string) $data['updatedAt']),
            userId: (int) $data['userId'],
            documentId: (int) $data['documentId'],
            status: Status::from((string) $data['status']),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapAction(array $data): DocumentAction
    {
        return new DocumentAction(
            id: (string) $data['id'],
            createdAt: new DateTimeImmutable((string) $data['createdAt']),
            updatedAt: new DateTimeImmutable((string) $data['updatedAt']),
            userId: (int) $data['userId'],
            datetime: new DateTimeImmutable((string) $data['datetime']),
            type: ActionType::from((string) $data['type']),
            userAgent: (string) $data['userAgent'],
            userIp: (string) $data['userIp'],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapItem(array $data): AcceptanceItem
    {
        return new AcceptanceItem(
            code: (string) $data['code'],
            document: $this->mapDocument($data['document']),
            documentAcceptanceStatus: isset($data['documentAcceptanceStatus'])
                ? $this->mapStatus($data['documentAcceptanceStatus'])
                : null,
        );
    }

    /**
     * @param list<string> $codes
     */
    private function joinCodes(array $codes): string
    {
        if ([] === $codes) {
            throw new InvalidArgumentException('Набор кодов документов не может быть пустым.');
        }
        foreach ($codes as $code) {
            $this->assertCode($code);
        }

        return implode(',', $codes);
    }

    /** Код попадает в путь запроса, поэтому проверяется до отправки */
    private function assertCode(string $code): void
    {
        if (1 !== preg_match(self::CODE_PATTERN, $code)) {
            throw new InvalidArgumentException(sprintf('Некорректный код «%s»: допустимы [a-z0-9_], длина 1–64.', $code));
        }
    }

    private function documentPath(int $userId, string $code): string
    {
        $this->assertCode($code);

        return '/users/' . $userId . '/documents/' . $code;
    }

    private function acknowledgementPath(int $userId, string $code): string
    {
        return '/users/' . $userId . '/acknowledgements/' . $code;
    }
}
