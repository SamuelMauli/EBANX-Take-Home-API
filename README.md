# EBANX Take-Home API

A simple banking API built for the EBANX Software Engineer technical challenge.

## What it does

Three endpoints that handle basic banking operations:

- **POST /reset** — Clears all state
- **GET /balance?account_id={id}** — Returns account balance
- **POST /event** — Handles deposits, withdrawals, and transfers

## Stack

- **PHP 8.2** with `strict_types` everywhere
- **Slim Framework 4** — Lightweight PSR-7/PSR-15 compliant micro-framework
- **PHPUnit 10** — Unit and integration tests
- **Nginx + PHP-FPM** — Production runtime

## Architecture

I chose a **layered architecture** with three clear layers:

```
HTTP Layer (Controller + Middleware)
        │
        ▼
Domain Layer (Service + Entity + Exceptions)
        │
        ▼
Infrastructure Layer (Repository Interface + Implementation)
```

### Why this structure?

The challenge explicitly asks to **separate business logic from the HTTP transport layer**. A layered approach does exactly that while staying simple enough for the scope of this project.

Each layer has one job:
- **Domain** knows about accounts, balances, deposits, withdrawals. It has zero knowledge of HTTP.
- **HTTP** translates requests into domain calls and domain results into responses. Zero business logic here.
- **Infrastructure** handles storage. Today it's an in-memory array. Tomorrow it could be MySQL or Redis — the domain doesn't care because it depends on an interface, not an implementation.

### Why not something simpler?

I could have put everything in one file with a switch statement. It would pass the tests. But the challenge also says "keep your code malleable" — twice. This structure means any modification (new event type, new storage backend, new validation) touches exactly one layer without rippling through the rest.

### Why not something more complex?

Hexagonal architecture, CQRS, or use cases per operation would be overkill here. Three operations don't justify that level of indirection. Knowing when NOT to over-engineer is as important as knowing the patterns.

## Design Decisions

### Rich Domain Model over Anemic

The `Account` entity owns its own rules. `deposit()` and `withdraw()` live on the entity, not in a service. The entity validates itself — it can never be in an invalid state.

### Two repository implementations

`InMemoryAccountRepository` works perfectly in tests — same process, shared state. But PHP creates a fresh process per HTTP request, so in production I use `FileAccountRepository` — stores accounts as JSON in a temp file with `LOCK_EX` for concurrency safety. This is exactly why the Repository Pattern exists: swap implementations without touching a single line of business logic. The domain doesn't know (or care) where data lives.

### int for balance, not float

Financial precision matters. Floating point arithmetic introduces rounding errors. The test suite uses integers, so I use integers. In a real system, I'd use a Money value object or work in cents.

### Exceptions for domain errors

`AccountNotFoundException`, `InsufficientFundsException`, `InvalidAmountException` — each with named constructors that carry rich context for debugging. The error handler middleware maps these to HTTP status codes. The domain never knows about 404 or 400.

### Interface for the repository

`AccountRepositoryInterface` lives in the Domain layer (not Infrastructure). The domain defines what it needs; infrastructure provides it. This is Dependency Inversion — the D in SOLID. It makes the code testable and swappable.

### Middleware for cross-cutting concerns

Security headers, CORS, rate limiting, error handling, input validation — all in PSR-15 middleware. The controller stays thin and focused.

## Security

Even though this is a take-home, I treat it like production code:

- **Security headers**: CSP, HSTS, X-Frame-Options, nosniff, no-store
- **Rate limiting**: 100 requests/minute per IP with sliding window
- **Input validation**: 1KB max payload, field allowlist (only `type`, `origin`, `destination`, `amount`)
- **Error handling**: Domain exceptions never leak stack traces. Unhandled errors return generic 500.
- **CORS**: Configured for the test suite to work cross-origin

## Project Structure

```
├── public/
│   └── index.php                    # Entry point (Composition Root)
├── src/
│   ├── Domain/
│   │   ├── Account.php              # Rich entity
│   │   ├── AccountService.php       # Business logic orchestration
│   │   ├── AccountRepositoryInterface.php
│   │   └── Exception/
│   │       ├── DomainException.php
│   │       ├── AccountNotFoundException.php
│   │       ├── InsufficientFundsException.php
│   │       ├── InvalidAmountException.php
│   │       └── InvalidEventTypeException.php
│   ├── Http/
│   │   ├── AccountController.php    # Thin controller
│   │   ├── ResponseFactory.php
│   │   ├── Response/
│   │   │   └── EventResponse.php    # DTO
│   │   └── Middleware/
│   │       ├── ErrorHandlerMiddleware.php
│   │       ├── CorsMiddleware.php
│   │       ├── SecurityHeadersMiddleware.php
│   │       ├── RateLimitMiddleware.php
│   │       └── InputValidationMiddleware.php
│   └── Infrastructure/
│       ├── InMemoryAccountRepository.php  # For tests
│       └── FileAccountRepository.php      # For production (PHP stateless model)
└── tests/
    ├── Unit/
    │   ├── AccountTest.php
    │   ├── AccountServiceTest.php
    │   ├── InMemoryAccountRepositoryTest.php
    │   └── FileAccountRepositoryTest.php
    └── Integration/
        └── ApiTest.php
```

## Running Locally

```bash
composer install
php -S localhost:8080 -t public/
```

## Running Tests

```bash
./vendor/bin/phpunit
```

## API Examples

```bash
# Reset state
curl -X POST http://localhost:8080/reset

# Deposit
curl -X POST http://localhost:8080/event \
  -H "Content-Type: application/json" \
  -d '{"type":"deposit","destination":"100","amount":10}'

# Check balance
curl "http://localhost:8080/balance?account_id=100"

# Withdraw
curl -X POST http://localhost:8080/event \
  -H "Content-Type: application/json" \
  -d '{"type":"withdraw","origin":"100","amount":5}'

# Transfer
curl -X POST http://localhost:8080/event \
  -H "Content-Type: application/json" \
  -d '{"type":"transfer","origin":"100","amount":5,"destination":"200"}'
```
