# ADR-003: Idempotency Keys for Financial Safety

## Status
Accepted

## Context
In a financial API, network failures between "server processes request" and "client receives response" cause clients to retry. Without idempotency, a deposit retry creates money from nothing.

## Decision
Support an optional `Idempotency-Key` header. If a key has been seen before (within 24h TTL), the stored response is replayed without re-executing the operation.

## How It Works
1. Client sends `POST /event` with `Idempotency-Key: abc`.
2. Middleware checks `IdempotencyStore` for key `abc`.
3. If found → return cached response with `X-Idempotency-Replayed: true`.
4. If not → execute request, store response, return normally.

## Alternatives Considered
- **No idempotency**: Simpler, but unsafe for financial operations.
- **Database-backed store**: More durable, but over-engineering for a file-based system.
- **UUID-based deduplication at domain level**: More complex, leaks infrastructure concerns into domain.

## Consequences
- **Positive**: Safe retries, industry-standard pattern (Stripe, EBANX, Adyen all use this).
- **Negative**: Adds a storage file and middleware. TTL cleanup prevents unbounded growth.
