# ADR-0002 — Plain PHP config file over `.env`

**Status:** Accepted

## Context
Modern PHP apps default to `.env` files parsed by `vlucas/phpdotenv`. This adds a dependency, a parse step, and a convention users must learn. appCore's setup wizard writes a config file from form input; that's simpler if the output is just a PHP array.

## Decision
`/config/config.php` is a PHP file that `return`s a nested associative array. No `.env`, no YAML, no parser.

```php
return [
    'app' => ['name' => '...', 'debug' => false],
    'db'  => ['host' => '...', ...],
];
```

## Consequences
- Zero parse cost. Config is opcache-cached like any other PHP file.
- Comments, computed values, and conditionals are possible in config if genuinely needed (rare).
- Secret rotation is a file edit — no separate `.env` layer.
- Less friction for operators who already know PHP.
- We give up the "12-factor, env-var-per-setting" ergonomics. That's fine: appCore is not deployed to Heroku-style platforms; shared hosting is the target.

## Alternatives considered
- **`.env` file** — adds a dependency and a convention for no gain in this deployment model.
- **YAML** — requires a parser; formatting errors produce cryptic failures.
- **Database-stored config** — chicken-and-egg with DB credentials; rejected.
