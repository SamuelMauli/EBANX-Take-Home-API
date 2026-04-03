<?php

declare(strict_types=1);

namespace Ebanx\Http\Middleware;

use Ebanx\Domain\Exception\AccountNotFoundException;
use Ebanx\Domain\Exception\InsufficientFundsException;
use Ebanx\Domain\Exception\InvalidAmountException;
use Ebanx\Domain\Exception\InvalidEventTypeException;
use Ebanx\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        try {
            return $handler->handle($request);
        } catch (AccountNotFoundException | InsufficientFundsException) {
            return ResponseFactory::plain(new SlimResponse(), '0', 404);
        } catch (InvalidAmountException | InvalidEventTypeException $e) {
            return ResponseFactory::plain(new SlimResponse(), $e->getMessage(), 400);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[EBANX-API] Unhandled exception: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));

            return ResponseFactory::plain(
                new SlimResponse(),
                'Internal Server Error',
                500,
            );
        }
    }
}
