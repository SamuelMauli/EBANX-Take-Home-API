# ADR-002: File-Based Storage with flock() over Database

## Status
Accepted

## Context
PHP-FPM creates a fresh process per request — in-memory state is lost between requests. We need persistence, but the challenge states "durability is NOT a requirement."

## Decision
Use `FileAccountRepository` backed by a JSON temp file with `flock()` for read-modify-write atomicity.

## Alternatives Considered
- **In-memory only**: Works in tests but state is lost between HTTP requests in PHP-FPM.
- **SQLite**: Would provide proper transactions but adds a dependency for a take-home.
- **MySQL/PostgreSQL**: Production-correct but infrastructure overhead for a coding challenge.
- **Redis**: Fast but requires running a separate service.

## Key Design Details
- `flock(LOCK_EX)` ensures exclusive access across PHP-FPM workers.
- Re-entrant locking (`lockDepth` counter) prevents deadlocks when `atomic()` wraps `find()`/`save()`.
- `InMemoryAccountRepository` is used in tests — same interface, zero I/O.

## Consequences
- **Positive**: Zero external dependencies, works anywhere PHP runs, demonstrates Repository Pattern value.
- **Negative**: Not suitable for production scale (single file, O(n) per operation). A real system would use MySQL with `SELECT ... FOR UPDATE`.
