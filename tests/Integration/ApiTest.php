<?php

declare(strict_types=1);

namespace Ebanx\Tests\Integration;

use Ebanx\Domain\AccountService;
use Ebanx\Http\AccountController;
use Ebanx\Http\Middleware\CorsMiddleware;
use Ebanx\Http\Middleware\ErrorHandlerMiddleware;
use Ebanx\Http\Middleware\IdempotencyMiddleware;
use Ebanx\Http\Middleware\InputValidationMiddleware;
use Ebanx\Http\Middleware\RequestIdMiddleware;
use Ebanx\Http\Middleware\SecurityHeadersMiddleware;
use Ebanx\Infrastructure\IdempotencyStore;
use Ebanx\Infrastructure\InMemoryAccountRepository;
use Ebanx\Infrastructure\TransactionLog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApiTest extends TestCase
{
    private \Slim\App $app;
    private TransactionLog $transactionLog;
    private IdempotencyStore $idempotencyStore;

    protected function setUp(): void
    {
        $repository = new InMemoryAccountRepository();
        $this->transactionLog = new TransactionLog(tempnam(sys_get_temp_dir(), 'ebanx_test_log_'));
        $this->idempotencyStore = new IdempotencyStore(tempnam(sys_get_temp_dir(), 'ebanx_test_idem_'));
        $service = new AccountService($repository, $this->transactionLog);
        $controller = new AccountController($service, $this->idempotencyStore);

        $this->app = AppFactory::create();
        $this->app->addBodyParsingMiddleware();
        $this->app->add(new InputValidationMiddleware());
        $this->app->add(new IdempotencyMiddleware($this->idempotencyStore));
        $this->app->add(new ErrorHandlerMiddleware());
        $this->app->add(new CorsMiddleware());
        $this->app->add(new SecurityHeadersMiddleware());
        $this->app->add(new RequestIdMiddleware());

        $this->app->post('/reset', [$controller, 'reset']);
        $this->app->get('/balance', [$controller, 'balance']);
        $this->app->post('/event', [$controller, 'event']);
        $this->app->get('/health', [$controller, 'health']);
    }

    protected function tearDown(): void
    {
        @unlink($this->transactionLog->getFilePath());
        // IdempotencyStore cleanup handled by tempnam lifecycle
    }

    #[Test]
    public function ebanx_01_reset_state(): void
    {
        $request = $this->createPostRequest('/reset');
        $response = $this->app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', (string) $response->getBody());
    }

    #[Test]
    public function ebanx_02_get_balance_for_nonexistent_account(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));

        $request = $this->createGetRequest('/balance', ['account_id' => '1234']);
        $response = $this->app->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('0', (string) $response->getBody());
    }

    #[Test]
    public function ebanx_03_create_account_with_initial_deposit(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));

        $request = $this->createJsonPostRequest('/event', [
            'type' => 'deposit',
            'destination' => '100',
            'amount' => 10,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString(
            '{"destination": {"id":"100", "balance":10}}',
            (string) $response->getBody(),
        );
    }

    #[Test]
    public function ebanx_04_deposit_into_existing_account(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));
        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 10,
        ]));

        $request = $this->createJsonPostRequest('/event', [
            'type' => 'deposit',
            'destination' => '100',
            'amount' => 10,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString(
            '{"destination": {"id":"100", "balance":20}}',
            (string) $response->getBody(),
        );
    }

    #[Test]
    public function ebanx_05_get_balance_for_existing_account(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));
        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 10,
        ]));
        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 10,
        ]));

        $request = $this->createGetRequest('/balance', ['account_id' => '100']);
        $response = $this->app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('20', (string) $response->getBody());
    }

    #[Test]
    public function ebanx_06_withdraw_from_nonexistent_account(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));

        $request = $this->createJsonPostRequest('/event', [
            'type' => 'withdraw',
            'origin' => '200',
            'amount' => 10,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('0', (string) $response->getBody());
    }

    #[Test]
    public function ebanx_07_withdraw_from_existing_account(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));
        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 10,
        ]));
        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 10,
        ]));

        $request = $this->createJsonPostRequest('/event', [
            'type' => 'withdraw',
            'origin' => '100',
            'amount' => 5,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString(
            '{"origin": {"id":"100", "balance":15}}',
            (string) $response->getBody(),
        );
    }

    #[Test]
    public function ebanx_08_transfer_between_accounts(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));
        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 10,
        ]));
        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 10,
        ]));
        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'withdraw', 'origin' => '100', 'amount' => 5,
        ]));

        $request = $this->createJsonPostRequest('/event', [
            'type' => 'transfer',
            'origin' => '100',
            'amount' => 15,
            'destination' => '300',
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString(
            '{"origin": {"id":"100", "balance":0}, "destination": {"id":"300", "balance":15}}',
            (string) $response->getBody(),
        );
    }

    #[Test]
    public function ebanx_09_transfer_from_nonexistent_origin(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));

        $request = $this->createJsonPostRequest('/event', [
            'type' => 'transfer',
            'origin' => '200',
            'amount' => 15,
            'destination' => '300',
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('0', (string) $response->getBody());
    }

    #[Test]
    public function cors_preflight_returns_204(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('OPTIONS', '/event');
        $response = $this->app->handle($request);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function security_headers_are_present(): void
    {
        $request = $this->createPostRequest('/reset');
        $response = $this->app->handle($request);

        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function unknown_event_type_returns_400(): void
    {
        $request = $this->createJsonPostRequest('/event', [
            'type' => 'refund',
            'origin' => '100',
            'amount' => 10,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function json_response_has_correct_content_type(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));

        $request = $this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 10,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function empty_destination_returns_400(): void
    {
        $request = $this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '', 'amount' => 10,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function non_numeric_amount_returns_400(): void
    {
        $request = $this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 'abc',
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function excessive_amount_returns_400(): void
    {
        $request = $this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 9999999999,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function negative_deposit_returns_400(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));

        $request = $this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => -5,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function deposit_without_destination_returns_400(): void
    {
        $request = $this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'amount' => 10,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function withdraw_without_origin_returns_400(): void
    {
        $request = $this->createJsonPostRequest('/event', [
            'type' => 'withdraw', 'amount' => 10,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function transfer_without_destination_returns_400(): void
    {
        $request = $this->createJsonPostRequest('/event', [
            'type' => 'transfer', 'origin' => '100', 'amount' => 10,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function transfer_without_amount_returns_400(): void
    {
        $request = $this->createJsonPostRequest('/event', [
            'type' => 'transfer', 'origin' => '100', 'destination' => '200',
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function self_transfer_returns_400(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));
        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 50,
        ]));

        $request = $this->createJsonPostRequest('/event', [
            'type' => 'transfer', 'origin' => '100', 'destination' => '100', 'amount' => 10,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function self_transfer_does_not_alter_balance(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));
        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 50,
        ]));

        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'transfer', 'origin' => '100', 'destination' => '100', 'amount' => 10,
        ]));

        $request = $this->createGetRequest('/balance', ['account_id' => '100']);
        $response = $this->app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('50', (string) $response->getBody());
    }

    #[Test]
    public function float_amount_returns_400(): void
    {
        $request = $this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 10.5,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function balance_without_account_id_returns_400(): void
    {
        $request = $this->createGetRequest('/balance');
        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function balance_with_empty_account_id_returns_400(): void
    {
        $request = $this->createGetRequest('/balance', ['account_id' => '']);
        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function trimmed_account_id_matches_original(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));
        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 30,
        ]));

        // Deposit with spaces around ID should credit the same account
        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => ' 100 ', 'amount' => 20,
        ]));

        $request = $this->createGetRequest('/balance', ['account_id' => '100']);
        $response = $this->app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('50', (string) $response->getBody());
    }

    #[Test]
    public function whitespace_only_destination_returns_400(): void
    {
        $request = $this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '   ', 'amount' => 10,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- Health check ---

    #[Test]
    public function health_returns_200_with_status(): void
    {
        $request = $this->createGetRequest('/health');
        $response = $this->app->handle($request);

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('healthy', $body['status']);
        $this->assertArrayHasKey('timestamp', $body);
        $this->assertArrayHasKey('php_version', $body);
    }

    // --- Idempotency ---

    #[Test]
    public function idempotency_key_replays_same_response(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));

        // First request with idempotency key
        $request = $this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 10,
        ])->withHeader('Idempotency-Key', 'unique-key-123');
        $response1 = $this->app->handle($request);

        $this->assertSame(201, $response1->getStatusCode());
        $body1 = (string) $response1->getBody();

        // Second request with SAME key — should replay, not re-execute
        $request2 = $this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 10,
        ])->withHeader('Idempotency-Key', 'unique-key-123');
        $response2 = $this->app->handle($request2);

        $body2 = (string) $response2->getBody();

        // Same response replayed
        $this->assertSame($body1, $body2);
        $this->assertSame('true', $response2->getHeaderLine('X-Idempotency-Replayed'));
    }

    #[Test]
    public function idempotency_key_prevents_duplicate_deposit(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));

        // Deposit 10 twice with same key
        $request = $this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 10,
        ])->withHeader('Idempotency-Key', 'dup-key');
        $this->app->handle($request);
        $this->app->handle($request);

        // Balance should be 10 (not 20) — second was replayed, not executed
        $response = $this->app->handle(
            $this->createGetRequest('/balance', ['account_id' => '100'])
        );
        $this->assertSame('10', (string) $response->getBody());
    }

    #[Test]
    public function different_idempotency_keys_execute_independently(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));

        $request1 = $this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 10,
        ])->withHeader('Idempotency-Key', 'key-A');
        $this->app->handle($request1);

        $request2 = $this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 10,
        ])->withHeader('Idempotency-Key', 'key-B');
        $this->app->handle($request2);

        $response = $this->app->handle(
            $this->createGetRequest('/balance', ['account_id' => '100'])
        );
        $this->assertSame('20', (string) $response->getBody());
    }

    // --- Transaction log ---

    #[Test]
    public function transaction_log_records_operations(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));

        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 50,
        ]));
        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'withdraw', 'origin' => '100', 'amount' => 10,
        ]));

        $logs = $this->transactionLog->getAll();

        // reset + deposit + withdraw = 3 entries
        $this->assertCount(3, $logs);

        $this->assertSame('reset', $logs[0]['type']);

        $this->assertSame('deposit', $logs[1]['type']);
        $this->assertSame('100', $logs[1]['destination']);
        $this->assertSame(50, $logs[1]['amount']);
        $this->assertSame(0, $logs[1]['balance_before']);
        $this->assertSame(50, $logs[1]['balance_after']);

        $this->assertSame('withdraw', $logs[2]['type']);
        $this->assertSame('100', $logs[2]['origin']);
        $this->assertSame(10, $logs[2]['amount']);
        $this->assertSame(50, $logs[2]['balance_before']);
        $this->assertSame(40, $logs[2]['balance_after']);
    }

    #[Test]
    public function transaction_log_records_transfer_both_sides(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));
        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 100,
        ]));

        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'transfer', 'origin' => '100', 'destination' => '200', 'amount' => 30,
        ]));

        $logs = $this->transactionLog->getAll();
        $transferLog = end($logs);

        $this->assertSame('transfer', $transferLog['type']);
        $this->assertSame('100', $transferLog['origin']);
        $this->assertSame('200', $transferLog['destination']);
        $this->assertSame(30, $transferLog['amount']);
        $this->assertSame(100, $transferLog['origin_balance_before']);
        $this->assertSame(70, $transferLog['origin_balance_after']);
        $this->assertSame(0, $transferLog['destination_balance_before']);
        $this->assertSame(30, $transferLog['destination_balance_after']);
    }

    // --- Request ID ---

    #[Test]
    public function response_contains_request_id_header(): void
    {
        $request = $this->createPostRequest('/reset');
        $response = $this->app->handle($request);

        $requestId = $response->getHeaderLine('X-Request-ID');
        $this->assertNotEmpty($requestId);
        // UUID v4 format
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $requestId,
        );
    }

    #[Test]
    public function client_request_id_is_echoed_back(): void
    {
        $request = $this->createPostRequest('/reset')
            ->withHeader('X-Request-ID', 'my-trace-id-123');
        $response = $this->app->handle($request);

        $this->assertSame('my-trace-id-123', $response->getHeaderLine('X-Request-ID'));
    }

    // --- Structured error responses ---

    #[Test]
    public function validation_error_returns_structured_json(): void
    {
        $request = $this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 10.5,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('VALIDATION_ERROR', $body['error']['code']);
        $this->assertSame('Amount must be a whole number', $body['error']['message']);
    }

    #[Test]
    public function domain_error_returns_structured_json(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));
        $this->app->handle($this->createJsonPostRequest('/event', [
            'type' => 'deposit', 'destination' => '100', 'amount' => 10,
        ]));

        // Negative amount passes middleware but caught by domain
        $request = $this->createJsonPostRequest('/event', [
            'type' => 'withdraw', 'origin' => '100', 'amount' => -5,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('INVALID_AMOUNT', $body['error']['code']);
    }

    #[Test]
    public function not_found_errors_keep_ebanx_spec_format(): void
    {
        $this->app->handle($this->createPostRequest('/reset'));

        // 404 errors must stay as plain "0" per EBANX spec
        $request = $this->createGetRequest('/balance', ['account_id' => '999']);
        $response = $this->app->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('0', (string) $response->getBody());
        $this->assertSame('text/plain', $response->getHeaderLine('Content-Type'));
    }

    private function createGetRequest(string $uri, array $queryParams = []): \Psr\Http\Message\ServerRequestInterface
    {
        if (!empty($queryParams)) {
            $uri .= '?' . http_build_query($queryParams);
        }

        return (new ServerRequestFactory())->createServerRequest('GET', $uri);
    }

    private function createPostRequest(string $uri): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', $uri);
    }

    private function createJsonPostRequest(string $uri, array $data): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $uri);
        $request = $request->withHeader('Content-Type', 'application/json');
        $request = $request->withParsedBody($data);

        return $request;
    }
}
