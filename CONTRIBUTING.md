# Contributing to ScoutKeeper

Thank you for your interest in contributing to ScoutKeeper. This is a community project and contributions of all kinds are welcome — bug fixes, new features, language translations, documentation improvements, and more.

Please read this guide before submitting anything. It will save everyone time.

---

## Table of Contents

1. [Code of Conduct](#code-of-conduct)
2. [Ways to Contribute](#ways-to-contribute)
3. [Getting Started](#getting-started)
4. [Development Standards](#development-standards)
5. [Testing Requirements](#testing-requirements)
6. [Submitting a Pull Request](#submitting-a-pull-request)
7. [Contributing a Language Translation](#contributing-a-language-translation)
8. [Reporting Bugs](#reporting-bugs)
9. [Suggesting Features](#suggesting-features)

---

## Code of Conduct

ScoutKeeper is built for the global Scout community. We expect all contributors to be respectful, constructive, and inclusive. Contributions that conflict with Scout values will not be accepted.

---

## Ways to Contribute

- **Fix a bug** — check the issue tracker for open bugs
- **Build a feature** — check the roadmap and open issues before starting work
- **Add a language translation** — see the [translations section](#contributing-a-language-translation) below
- **Improve documentation** — corrections, clarifications, and additions are always welcome
- **Write or improve tests** — PHPUnit coverage improvements are always appreciated
- **Report a bug** — see the [bug reporting section](#reporting-bugs) below

---

## Getting Started

### Requirements

- PHP 8.2+
- MySQL 8.0+ (or MariaDB 10.6+)
- A local web server (e.g. XAMPP, Laragon, MAMP, or a plain Apache/Nginx setup)
- PHPUnit (for running tests)
- Git

### Setup

1. Fork the repository on GitHub
2. Clone your fork locally:
   ```bash
   git clone https://github.com/YOUR-USERNAME/scoutkeeper.git
   ```
3. Create a database and run the setup wizard at `http://localhost/q9-sk10/`
4. Create a new branch for your work:
   ```bash
   git checkout -b feature/your-feature-name
   ```

---

## Development Standards

ScoutKeeper is intended to be readable and maintainable by developers of varying experience levels. Please follow these standards in all contributions.

### Code Comments

- **All functions must have a comment block** explaining what the function does, its parameters, and its return value
- **Complex logic must be commented inline** — if it takes more than a moment to understand what a block of code does, add a comment
- Write comments for the next developer, not for yourself

Example:
```php
/**
 * Returns all active members belonging to a given organisational node.
 *
 * @param int $node_id  The ID of the organisational node
 * @param bool $include_children  Whether to include members from child nodes
 * @return array  Array of member records
 */
function get_members_by_node(int $node_id, bool $include_children = false): array {
    // ...
}
```

### Coding Style

- Follow **PSR-12** coding standards
- Use meaningful variable and function names — avoid abbreviations unless universally understood
- Keep functions small and focused — one function, one responsibility
- Avoid hardcoded strings in logic — use the language/translation system for any user-facing text
- No inline SQL in business logic — use the database abstraction layer

### Architecture

- The organisational structure, field definitions, and permissions are **data-driven** — do not hardcode hierarchy levels, field types, or permission rules
- Do not introduce dependencies that require CLI access or are unavailable on standard shared hosting
- If adding a Composer dependency, ensure it is bundled — do not assume Composer is available on the target server

---

## Testing Requirements

**All contributions must include appropriate tests.** Pull requests without tests will not be merged unless the change is a documentation-only update.

### Running Tests

```bash
./vendor/bin/phpunit
```

### What to Test

- New functions must have unit tests covering expected behaviour and edge cases
- Bug fixes must include a test that would have caught the bug
- Any change to the permissions, finance/ledger, or authentication modules requires tests — these are critical paths

### Test Location

Tests live in the `/tests` directory, mirroring the structure of `/src`. For example:

```
/src/Members/Registration.php  →  /tests/Members/RegistrationTest.php
```

---

## Submitting a Pull Request

1. Ensure your branch is up to date with `main`
2. Run the full test suite and confirm it passes
3. Write a clear PR description:
   - What does this change do?
   - Why is it needed?
   - Are there any side effects or things reviewers should pay particular attention to?
4. Reference any related issues (e.g. `Closes #42`)
5. Submit the PR against the `main` branch

**Please keep PRs focused.** One feature or bug fix per PR. Large PRs are harder to review and slower to merge.

---

## Contributing a Language Translation

Translations are one of the most valuable contributions you can make — they make ScoutKeeper accessible to Scout organisations around the world.

### How Translations Work

- All translatable strings live in JSON language files in the `/lang` directory
- `en.json` is the master English file and is the source of truth
- Each language has its own file, e.g. `fr.json`, `mt.json`, `es.json`
- The system can export a **master language file** from the admin panel containing every string that needs translating

### Creating a New Translation

1. From the ScoutKeeper admin panel, export the master language file
2. Copy the file and rename it to your language code (e.g. `fr.json` for French)
3. Translate each string value — do not change the keys
4. Test the translation by placing the file in `/lang/` on your installation and switching the system language
5. Submit the file as a pull request

### Translation Guidelines

- Translate meaning, not just words — Scout terminology varies by country; use what is natural for your organisation
- If a term has no direct equivalent, leave the English string and add a comment in the PR explaining why
- Do not translate system placeholders like `{name}` or `{date}` — these are replaced at runtime

### AI-Generated Starter Packs

If you are starting a new language, an AI-generated starter pack can be produced from the English master file to give you a base to work from. Raise an issue with the label `translation-request` and include the target language. A starter pack will be generated and attached to the issue for you to review and refine.

---

## Reporting Bugs

Before reporting a bug, check the issue tracker to see if it has already been reported.

When filing a bug report, please include:

- ScoutKeeper version
- PHP version and MySQL version
- Hosting environment (shared hosting, local, VPS, etc.)
- Steps to reproduce the issue
- What you expected to happen
- What actually happened
- Any relevant error messages or logs

---

## Suggesting Features

Feature suggestions are welcome. Before opening a feature request:

- Check the issue tracker and roadmap — it may already be planned
- Consider whether the feature is useful to Scout organisations generally, not just your specific use case

When filing a feature request, please include:

- A clear description of the feature
- The problem it solves or the need it addresses
- Any examples from other systems (if relevant)

---

## Questions?

If you are unsure about anything before starting work, open a discussion on the GitHub repository or raise an issue with the `question` label. We would rather answer a question upfront than review work that goes in the wrong direction.

---

ScoutKeeper is maintained by [QuadNine Ltd](https://quadnine.mt).
