<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ebanx\Domain\AccountService;
use Ebanx\Http\AccountController;
use Ebanx\Http\Middleware\CorsMiddleware;
use Ebanx\Http\Middleware\ErrorHandlerMiddleware;
use Ebanx\Http\Middleware\InputValidationMiddleware;
use Ebanx\Http\Middleware\RateLimitMiddleware;
use Ebanx\Http\Middleware\SecurityHeadersMiddleware;
use Ebanx\Infrastructure\FileAccountRepository;
use Slim\Factory\AppFactory;

// Composition Root — all dependency wiring in one place
// FileAccountRepository persists state between PHP requests via temp file
$repository = new FileAccountRepository();
$service = new AccountService($repository);
$controller = new AccountController($service);

$app = AppFactory::create();

// Middleware stack (last added = first executed)
$app->addBodyParsingMiddleware();
$app->add(new InputValidationMiddleware());
$app->add(new ErrorHandlerMiddleware());
$app->add(new RateLimitMiddleware());
$app->add(new CorsMiddleware());
$app->add(new SecurityHeadersMiddleware());
$app->addRoutingMiddleware();

// Routes
$app->post('/reset', [$controller, 'reset']);
$app->get('/balance', [$controller, 'balance']);
$app->post('/event', [$controller, 'event']);

$app->run();
