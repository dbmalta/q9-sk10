# appCore — Tests

Two test systems ship with the scaffold:

## 1. PHPUnit (unit + service tests)

```
vendor/bin/phpunit
```

Tests live alongside the class they exercise:

- `tests/Core/` — Core primitives (Database, Router, Encryption, ...)
- `tests/Modules/{Name}/` — Module-specific services and controllers
- `tests/fixtures/bootstrap.php` — loaded automatically by PHPUnit

### Database-backed tests

Export these env vars before running. Tests that need them will
`markTestSkipped` if they're missing:

```
export APPCORE_TEST_DB_HOST=localhost
export APPCORE_TEST_DB_PORT=3306
export APPCORE_TEST_DB_NAME=appcore_test
export APPCORE_TEST_DB_USER=appcore_test
export APPCORE_TEST_DB_PASSWORD=appcore_test
```

The test DB should be a dedicated schema — tests create and drop helper
tables per run.

## 2. Playwright (end-to-end)

E2E specs go in `tests/e2e/specs/` (not scaffolded here — add when your
project grows a UI worth testing). A typical setup:

```
cd tests/e2e
npm install
npx playwright install
npx playwright test
```

Your project's seeder should populate the test DB with deterministic data
before each run.

## Writing tests

- One test class per production class.
- Don't mock PDO — hit the test DB. Integration beats isolation at the
  service layer.
- Reset state in `setUp()` or `tearDown()`; don't let one test leak into
  another.
- Use `$this->markTestSkipped(...)` when a precondition isn't met — not
  `$this->assertTrue(false)`.
