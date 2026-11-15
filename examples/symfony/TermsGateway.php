<?php

declare(strict_types=1);

namespace IWD\TermsSdk\Examples\Symfony;

use IWD\TermsSdk\AcceptanceCheck;
use IWD\TermsSdk\AcceptResult;
use IWD\TermsSdk\AcknowledgementCheck;
use IWD\TermsSdk\TermsClient;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Пример адаптера продукта: единственная точка связи с Terms.
 * В продукте копируйте в App\Common\Terms (или свой BC) при opt-in; домен TermsClient не импортирует.
 *
 * Любая ошибка сервиса превращается в TermsUnavailableHttpException (в продукте — 502): гейт закрыт, а не открыт.
 */
final readonly class TermsGateway
{
    public function __construct(
        private TermsClient $termsClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Приняты ли пользователем актуальные версии документов набора. Коды — строки, enum кодов ведёт продукт.
     *
     * @param list<string> $codes
     */
    public function checkAcceptance(int $userId, array $codes): AcceptanceCheck
    {
        return $this->call(
            operation: 'checkAcceptance',
            userId: $userId,
            request: fn (): AcceptanceCheck => $this->termsClient->checkAcceptance($userId, $codes),
        );
    }

    /** Акцепт актуальной версии документа. null, если документа с таким кодом нет в сервисе */
    public function accept(int $userId, string $code, string $userAgent, string $ip): ?AcceptResult
    {
        return $this->call(
            operation: 'accept',
            userId: $userId,
            request: fn (): ?AcceptResult => $this->termsClient->accept($userId, $code, $userAgent, $ip),
        );
    }

    /**
     * Пакетная проверка для cron: userId => приняты ли все документы набора.
     *
     * @param list<int>    $userIds
     * @param list<string> $codes
     *
     * @return array<int, bool>
     */
    public function checkAcceptanceBatch(array $userIds, array $codes): array
    {
        return $this->call(
            operation: 'checkAcceptanceBatch',
            userId: null,
            request: fn (): array => $this->termsClient->checkAcceptanceBatch($userIds, $codes),
        );
    }

    public function acknowledgement(int $userId, string $code, ?int $requiredVersion = null): ?AcknowledgementCheck
    {
        return $this->call(
            operation: 'acknowledgement',
            userId: $userId,
            request: fn (): ?AcknowledgementCheck => $this->termsClient->acknowledgement($userId, $code, $requiredVersion),
        );
    }

    /**
     * @template T
     *
     * @param callable(): T $request
     *
     * @return T
     */
    private function call(string $operation, ?int $userId, callable $request): mixed
    {
        try {
            return $request();
        } catch (HttpExceptionInterface $exception) {
            $this->logger->error('Terms request failed', [
                'operation' => $operation,
                'userId' => $userId,
                'status' => $exception->getResponse()->getStatusCode(),
            ]);
            throw $exception;
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Terms transport failed', [
                'operation' => $operation,
                'userId' => $userId,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }
}
