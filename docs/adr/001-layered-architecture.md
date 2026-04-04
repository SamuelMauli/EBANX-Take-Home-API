# ADR-001: Layered Architecture over Hexagonal

## Status
Accepted

## Context
The EBANX challenge requires clean separation between business logic and HTTP transport. We need an architecture that is clear, testable, and easy to evolve — but not over-engineered for three operations.

## Decision
Use a three-layer architecture (HTTP → Domain → Infrastructure) with Dependency Inversion on the repository boundary.

## Alternatives Considered
- **Single file**: Would pass tests but violates the challenge requirement for separation of concerns.
- **Hexagonal/Ports & Adapters**: Correct pattern, but adds unnecessary indirection (use cases, ports, adapters) for three operations.
- **CQRS**: Read/write separation is overkill when reads are a single balance lookup.

## Consequences
- **Positive**: Each layer has one job, easy to test in isolation, clear where new code goes.
- **Negative**: Slightly more files than a minimal solution. Acceptable trade-off for maintainability.
