# /var/

Runtime state. Everything here is generated at runtime and should not be
committed:

- `cache/` — Twig cache, i18n cache, `cron_last_run.txt`
- `logs/` — `errors.json`, `app.json`, `slow-queries.json`, `requests.json`,
  `smtp.json`, `cron.json`, `updates.json`
- `sessions/` — file-based PHP sessions
- `updates/` — staged update zips + backup directories
- `maintenance.flag` — present when the app is in maintenance mode
- `update_token.txt` — single-use token consumed by `/updater/run.php`

`.gitkeep` files exist so the directory structure is preserved in git while
the contents are ignored via `.gitignore`.

The web server user must be able to write to every subdirectory here.
