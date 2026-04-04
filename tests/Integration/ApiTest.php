<?php

declare(strict_types=1);

namespace Ebanx\Tests\Integration;

use Ebanx\Domain\AccountService;
use Ebanx\Http\AccountController;
use Ebanx\Http\Middleware\CorsMiddleware;
use Ebanx\Http\Middleware\ErrorHandlerMiddleware;
use Ebanx\Http\Middleware\InputValidationMiddleware;
use Ebanx\Http\Middleware\SecurityHeadersMiddleware;
use Ebanx\Infrastructure\InMemoryAccountRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApiTest extends TestCase
{
    private \Slim\App $app;

    protected function setUp(): void
    {
        $repository = new InMemoryAccountRepository();
        $service = new AccountService($repository);
        $controller = new AccountController($service);

        $this->app = AppFactory::create();
        $this->app->addBodyParsingMiddleware();
        $this->app->add(new InputValidationMiddleware());
        $this->app->add(new ErrorHandlerMiddleware());
        $this->app->add(new CorsMiddleware());
        $this->app->add(new SecurityHeadersMiddleware());

        $this->app->post('/reset', [$controller, 'reset']);
        $this->app->get('/balance', [$controller, 'balance']);
        $this->app->post('/event', [$controller, 'event']);
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
