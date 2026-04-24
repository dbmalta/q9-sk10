# /data/

Persistent user data that must survive updates. The contents are gitignored.

- `data/backups/` — database dumps created via the Admin → Backups screen
- `data/uploads/` — user-uploaded files (project-specific modules add their
  own subdirectories under here)

The web server user must be able to write here. Permissions of 0750 and
group ownership matching the web server user are a reasonable default.
