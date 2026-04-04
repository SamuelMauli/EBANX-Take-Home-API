# ADR-004: Integer Balances, Float Rejection

## Status
Accepted

## Context
Financial calculations with floating point produce rounding errors. `0.1 + 0.2 !== 0.3` in IEEE 754. The EBANX test suite uses integer amounts, confirming this design.

## Decision
- Store balances as `int` (PHP native).
- Reject float amounts at the middleware level with HTTP 400.
- No silent truncation — `{"amount": 10.5}` is an error, not `10`.

## Alternatives Considered
- **Float with rounding**: Introduces precision bugs that are hard to detect and reproduce.
- **BCMath/Money VO**: Correct for production with multi-currency, but over-engineering for integer-only test suite.
- **Cents as integers**: Standard pattern (amount in smallest currency unit). Our approach is equivalent since the test suite doesn't specify currency.

## Consequences
- **Positive**: Zero precision issues, client-side bugs caught early (not silently absorbed).
- **Negative**: Cannot represent sub-unit amounts. Acceptable given the challenge scope.
