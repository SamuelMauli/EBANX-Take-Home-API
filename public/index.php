<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ebanx\Domain\AccountService;
use Ebanx\Http\AccountController;
use Ebanx\Http\Middleware\CorsMiddleware;
use Ebanx\Http\Middleware\ErrorHandlerMiddleware;
use Ebanx\Http\Middleware\IdempotencyMiddleware;
use Ebanx\Http\Middleware\InputValidationMiddleware;
use Ebanx\Http\Middleware\RateLimitMiddleware;
use Ebanx\Http\Middleware\RequestIdMiddleware;
use Ebanx\Http\Middleware\SecurityHeadersMiddleware;
use Ebanx\Infrastructure\FileAccountRepository;
use Ebanx\Infrastructure\IdempotencyStore;
use Ebanx\Infrastructure\TransactionLog;

// Composition Root — all dependency wiring in one place
$repository = new FileAccountRepository();
$transactionLog = new TransactionLog();
$idempotencyStore = new IdempotencyStore();
$service = new AccountService($repository, $transactionLog);
$controller = new AccountController($service, $idempotencyStore);

$app = \Slim\Factory\AppFactory::create();

// Middleware stack (last added = first executed)
$app->addBodyParsingMiddleware();
$app->add(new InputValidationMiddleware());
$app->add(new IdempotencyMiddleware($idempotencyStore));
$app->add(new ErrorHandlerMiddleware());
$app->add(new RateLimitMiddleware());
$app->add(new CorsMiddleware());
$app->add(new SecurityHeadersMiddleware());
$app->add(new RequestIdMiddleware());
$app->addRoutingMiddleware();

// Routes
$app->post('/reset', [$controller, 'reset']);
$app->get('/balance', [$controller, 'balance']);
$app->post('/event', [$controller, 'event']);
$app->get('/health', [$controller, 'health']);

$app->run();
