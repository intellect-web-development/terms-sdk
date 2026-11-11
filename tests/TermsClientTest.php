<?php

declare(strict_types=1);

namespace IWD\TermsSdk\Tests;

use InvalidArgumentException;
use IWD\TermsSdk\ActionType;
use IWD\TermsSdk\MissingReason;
use IWD\TermsSdk\Status;
use IWD\TermsSdk\TermsClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

final class TermsClientTest extends TestCase
{
    private const string DOCUMENT = '{"id":"5","createdAt":"2026-11-10T10:00:00+00:00","updatedAt":"2026-11-10T10:00:00+00:00","name":"Политика","description":null,"link":"https://site.test/privacy","code":"privacy_policy","version":2}';
    private const string STATUS = '{"id":"9","createdAt":"2026-11-10T10:00:00+00:00","updatedAt":"2026-11-10T11:00:00+00:00","userId":42,"documentId":5,"status":"accepted"}';

    /** @var array{method: string, url: string, headers: list<string>, body: string} */
    private array $captured = [];

    public function testCreateDocumentPostsFieldsAndMapsDocument(): void
    {
        $document = $this->client(self::DOCUMENT)->createDocument(
            code: 'privacy_policy',
            version: 2,
            name: 'Политика',
            description: null,
            link: 'https://site.test/privacy',
        );

        self::assertSame('POST', $this->captured['method']);
        self::assertSame('http://terms.test/api/sdk/documents', $this->captured['url']);
        self::assertSame(
            ['code' => 'privacy_policy', 'version' => 2, 'name' => 'Политика', 'description' => null, 'link' => 'https://site.test/privacy'],
            $this->body(),
        );
        self::assertSame('5', $document->id);
        self::assertSame('privacy_policy', $document->code);
        self::assertSame(2, $document->version);
        self::assertNull($document->description);
        self::assertSame('2026-11-10T10:00:00+00:00', $document->createdAt->format(DATE_ATOM));
    }

    public function testCreateDocumentAcceptsCreatedResponse(): void
    {
        $http = new MockHttpClient(new MockResponse(self::DOCUMENT, ['http_code' => 201]));

        $document = (new TermsClient($http, 'http://terms.test', 't'))->createDocument('privacy_policy', 2, 'Политика', 'Описание', 'https://site.test/privacy');

        self::assertSame('5', $document->id);
    }

    public function testCurrentDocumentsSendsCodesAndMapsItems(): void
    {
        $documents = $this->client('{"items":[' . self::DOCUMENT . ']}')->currentDocuments(['privacy_policy', 'user_agreement']);

        self::assertSame('GET', $this->captured['method']);
        self::assertSame('http://terms.test/api/sdk/documents/current?codes=privacy_policy%2Cuser_agreement', $this->captured['url']);
        self::assertCount(1, $documents);
        self::assertSame('privacy_policy', $documents[0]->code);
    }

    public function testCurrentDocumentsWithoutCodesMakesNoRequest(): void
    {
        $http = new MockHttpClient(static fn () => self::fail('Запрос не должен отправляться'));

        self::assertSame([], (new TermsClient($http, 'http://terms.test', 't'))->currentDocuments([]));
    }

    public function testAcceptAddressesDocumentByCodeAndMapsResult(): void
    {
        $result = $this->client('{"document":' . self::DOCUMENT . ',"documentAcceptanceStatus":' . self::STATUS . ',"alreadyAccepted":true}')
            ->accept(userId: 42, code: 'privacy_policy', userAgent: 'UA/1.0', ip: '10.0.0.1');

        self::assertSame('POST', $this->captured['method']);
        self::assertSame('http://terms.test/api/sdk/users/42/documents/privacy_policy/accept', $this->captured['url']);
        self::assertSame(['userAgent' => 'UA/1.0', 'ip' => '10.0.0.1'], $this->body());
        self::assertSame('5', $result?->document->id);
        self::assertSame(Status::Accepted, $result->documentAcceptanceStatus->status);
        self::assertSame(42, $result->documentAcceptanceStatus->userId);
        self::assertSame(5, $result->documentAcceptanceStatus->documentId);
        self::assertTrue($result->alreadyAccepted);
    }

    public function testAcceptReturnsNullWhenDocumentMissing(): void
    {
        $http = new MockHttpClient(new MockResponse('{}', ['http_code' => 404]));

        self::assertNull((new TermsClient($http, 'http://terms.test', 't'))->accept(42, 'privacy_policy', 'UA', 'ip'));
    }

    public function testRevokePostsUserAgentAndIp(): void
    {
        $status = str_replace('"accepted"', '"revoked"', self::STATUS);

        $result = $this->client('{"document":' . self::DOCUMENT . ',"documentAcceptanceStatus":' . $status . '}')
            ->revoke(userId: 42, code: 'privacy_policy', userAgent: 'UA/1.0', ip: '10.0.0.1');

        self::assertSame('http://terms.test/api/sdk/users/42/documents/privacy_policy/revoke', $this->captured['url']);
        self::assertSame(['userAgent' => 'UA/1.0', 'ip' => '10.0.0.1'], $this->body());
        self::assertSame(Status::Revoked, $result?->documentAcceptanceStatus->status);
        self::assertSame('privacy_policy', $result->document->code);
    }

    public function testRevokeReturnsNullWhenDocumentMissing(): void
    {
        $http = new MockHttpClient(new MockResponse('{}', ['http_code' => 404]));

        self::assertNull((new TermsClient($http, 'http://terms.test', 't'))->revoke(42, 'privacy_policy', 'UA', 'ip'));
    }

    public function testHistoryMapsDocumentAndActions(): void
    {
        $action = '{"id":"1","createdAt":"2026-11-10T10:00:00+00:00","updatedAt":"2026-11-10T10:00:00+00:00","userId":42,"datetime":"2026-11-10T10:00:00+00:00","type":"revocation","userAgent":"UA","userIp":"10.0.0.1"}';

        $history = $this->client('{"document":' . self::DOCUMENT . ',"actions":[' . $action . ']}')->history(userId: 42, code: 'privacy_policy');

        self::assertSame('GET', $this->captured['method']);
        self::assertSame('http://terms.test/api/sdk/users/42/documents/privacy_policy/history', $this->captured['url']);
        self::assertSame('privacy_policy', $history?->document->code);
        self::assertCount(1, $history->actions);
        self::assertSame(ActionType::Revocation, $history->actions[0]->type);
        self::assertSame('10.0.0.1', $history->actions[0]->userIp);
        self::assertSame('2026-11-10T10:00:00+00:00', $history->actions[0]->datetime->format(DATE_ATOM));
    }

    public function testHistoryReturnsNullWhenDocumentMissing(): void
    {
        $http = new MockHttpClient(new MockResponse('{}', ['http_code' => 404]));

        self::assertNull((new TermsClient($http, 'http://terms.test', 't'))->history(42, 'privacy_policy'));
    }

    public function testCheckAcceptanceMapsCheckInfoAndMissing(): void
    {
        $agreement = str_replace(['"privacy_policy"', '"id":"5"', '"name":"Политика"'], ['"user_agreement"', '"id":"4"', '"name":"Соглашение"'], self::DOCUMENT);
        $body = '{"userId":42,"accepted":false,"checkInfo":['
            . '{"code":"privacy_policy","document":' . self::DOCUMENT . ',"documentAcceptanceStatus":null},'
            . '{"code":"user_agreement","document":' . $agreement . ',"documentAcceptanceStatus":' . self::STATUS . '}'
            . '],"missing":['
            . '{"code":"privacy_policy","name":"Политика","reason":"not_accepted"},'
            . '{"code":"unknown_doc","name":null,"reason":"document_not_found"}'
            . ']}';

        $check = $this->client($body)->checkAcceptance(42, ['user_agreement', 'privacy_policy', 'unknown_doc']);

        self::assertSame('GET', $this->captured['method']);
        self::assertSame(
            'http://terms.test/api/sdk/users/42/documents/acceptance?codes=user_agreement%2Cprivacy_policy%2Cunknown_doc',
            $this->captured['url'],
        );
        self::assertSame(42, $check->userId);
        self::assertFalse($check->accepted);
        self::assertCount(2, $check->checkInfo);
        self::assertSame('privacy_policy', $check->checkInfo[0]->code);
        self::assertNull($check->checkInfo[0]->documentAcceptanceStatus);
        self::assertSame(Status::Accepted, $check->checkInfo[1]->documentAcceptanceStatus?->status);
        self::assertSame('user_agreement', $check->checkInfo[1]->document->code);
        self::assertCount(2, $check->missing);
        self::assertSame('Политика', $check->missing[0]->name);
        self::assertSame(MissingReason::NotAccepted, $check->missing[0]->reason);
        self::assertNull($check->missing[1]->name);
        self::assertSame(MissingReason::DocumentNotFound, $check->missing[1]->reason);
    }

    public function testCheckAcceptanceRejectsEmptyCodes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client('{}')->checkAcceptance(42, []);
    }

    public function testCheckAcceptanceRejectsInvalidCode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client('{}')->checkAcceptance(42, ['user_agreement', '../admin']);
    }

    public function testCheckAcceptanceBatchMapsFlagsByUserId(): void
    {
        $result = $this->client('{"items":[{"userId":1,"accepted":true},{"userId":2,"accepted":false}]}')
            ->checkAcceptanceBatch([1, 2], ['user_agreement', 'privacy_policy']);

        self::assertSame('POST', $this->captured['method']);
        self::assertSame('http://terms.test/api/sdk/documents/acceptance/batch', $this->captured['url']);
        self::assertSame(['userIds' => [1, 2], 'codes' => ['user_agreement', 'privacy_policy']], $this->body());
        self::assertSame([1 => true, 2 => false], $result);
    }

    public function testCheckAcceptanceBatchWithoutUsersMakesNoRequest(): void
    {
        $http = new MockHttpClient(static fn () => self::fail('Запрос не должен отправляться'));

        self::assertSame([], (new TermsClient($http, 'http://terms.test', 't'))->checkAcceptanceBatch([], ['user_agreement']));
    }

    public function testCheckAcceptanceBatchRejectsEmptyCodes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client('{}')->checkAcceptanceBatch([1], []);
    }

    public function testAcknowledgementSendsRequiredVersionAndMapsCheck(): void
    {
        $check = $this->client('{"code":"rebalancing_onboarding","completed":true,"version":2}')
            ->acknowledgement(42, 'rebalancing_onboarding', requiredVersion: 2);

        self::assertSame('GET', $this->captured['method']);
        self::assertSame('http://terms.test/api/sdk/users/42/acknowledgements/rebalancing_onboarding?requiredVersion=2', $this->captured['url']);
        self::assertSame('rebalancing_onboarding', $check?->code);
        self::assertTrue($check->completed);
        self::assertSame(2, $check->version);
    }

    public function testAcknowledgementWithoutRequiredVersionHasNoQuery(): void
    {
        $check = $this->client('{"code":"rebalancing_onboarding","completed":false,"version":null}')
            ->acknowledgement(42, 'rebalancing_onboarding');

        self::assertSame('http://terms.test/api/sdk/users/42/acknowledgements/rebalancing_onboarding', $this->captured['url']);
        self::assertFalse($check?->completed);
        self::assertNull($check->version);
    }

    public function testAcknowledgementReturnsNullOn404(): void
    {
        $http = new MockHttpClient(new MockResponse('{}', ['http_code' => 404]));

        self::assertNull((new TermsClient($http, 'http://terms.test', 't'))->acknowledgement(42, 'rebalancing_onboarding'));
    }

    public function testCompleteAcknowledgementPostsVersionAndMapsResult(): void
    {
        $response = '{"id":"3","code":"rebalancing_onboarding","version":2,"completedAt":"2026-11-10T12:00:00+00:00"}';

        $acknowledgement = $this->client($response)->completeAcknowledgement(
            userId: 42,
            code: 'rebalancing_onboarding',
            version: 2,
            userAgent: 'UA/1.0',
            userIp: '10.0.0.1',
        );

        self::assertSame('POST', $this->captured['method']);
        self::assertSame('http://terms.test/api/sdk/users/42/acknowledgements/rebalancing_onboarding', $this->captured['url']);
        self::assertSame(['version' => 2, 'userAgent' => 'UA/1.0', 'userIp' => '10.0.0.1'], $this->body());
        self::assertSame('3', $acknowledgement->id);
        self::assertSame('rebalancing_onboarding', $acknowledgement->code);
        self::assertSame(2, $acknowledgement->version);
        self::assertSame('2026-11-10T12:00:00+00:00', $acknowledgement->completedAt->format(DATE_ATOM));
    }

    public function testEveryRequestSendsBearerToken(): void
    {
        $this->client('{"items":[]}')->currentDocuments(['user_agreement']);

        self::assertContains('Authorization: Bearer jwt-token', $this->captured['headers']);
    }

    public function testBaseUrlTrailingSlashIsTrimmed(): void
    {
        (new TermsClient($this->mock('{"items":[]}'), 'http://terms.test///', 't'))->currentDocuments(['user_agreement']);

        self::assertStringStartsWith('http://terms.test/api/sdk/documents/current', $this->captured['url']);
    }

    public function testCodeIsValidatedBeforeRequest(): void
    {
        $http = new MockHttpClient(static fn () => self::fail('Запрос не должен отправляться'));
        $client = new TermsClient($http, 'http://terms.test', 't');

        foreach (['', 'User-Agreement', 'a/b', str_repeat('a', 65)] as $code) {
            try {
                $client->accept(42, $code, 'UA', 'ip');
                self::fail('Ожидалось исключение для кода «' . $code . '»');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCreateDocumentRethrowsValidationError(): void
    {
        $http = new MockHttpClient(new MockResponse('{}', ['http_code' => 422]));

        $this->expectException(ClientExceptionInterface::class);
        (new TermsClient($http, 'http://terms.test', 't'))->createDocument('privacy_policy', 1, 'n', null, 'l');
    }

    public function testAcceptRethrowsForbidden(): void
    {
        $http = new MockHttpClient(new MockResponse('{}', ['http_code' => 403]));

        $this->expectException(ClientExceptionInterface::class);
        (new TermsClient($http, 'http://terms.test', 't'))->accept(42, 'privacy_policy', 'UA', 'ip');
    }

    public function testCheckAcceptanceRethrowsServerError(): void
    {
        $http = new MockHttpClient(new MockResponse('{}', ['http_code' => 503]));

        $this->expectException(ServerExceptionInterface::class);
        (new TermsClient($http, 'http://terms.test', 't'))->checkAcceptance(42, ['user_agreement']);
    }

    public function testStateEnums(): void
    {
        self::assertSame(['accepted', 'revoked', 'not_accepted'], Status::states());
        self::assertSame(['acceptance', 'revocation'], ActionType::states());
        self::assertSame(['document_not_found', 'not_accepted'], MissingReason::states());
    }

    private function client(string $responseBody): TermsClient
    {
        return new TermsClient($this->mock($responseBody), 'http://terms.test/', 'jwt-token');
    }

    private function mock(string $responseBody): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use ($responseBody): MockResponse {
            $this->captured = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['headers'],
                'body' => (string) ($options['body'] ?? ''),
            ];

            return new MockResponse($responseBody, ['http_code' => 200]);
        });
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return json_decode($this->captured['body'], true, 512, JSON_THROW_ON_ERROR);
    }
}
