# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in ScoutKeeper, please report it responsibly. **Do not open a public issue.**

**Email:** [security@quadnine.mt](mailto:security@quadnine.mt)

Include the following in your report:

- A description of the vulnerability and its potential impact
- Steps to reproduce the issue
- The version of ScoutKeeper affected
- Any relevant logs, screenshots, or proof-of-concept code

## Scope

### In scope

- The ScoutKeeper application code (PHP, Twig templates, JavaScript)
- Authentication and session management
- Access control and permission enforcement
- Data encryption (medical notes, MFA secrets)
- File upload handling
- The auto-update mechanism and signature verification
- The setup wizard
- The cron runner
- SQL injection, XSS, CSRF, and other OWASP Top 10 vulnerabilities

### Out of scope

- Vulnerabilities in third-party dependencies (report these to the upstream project, but do let us know so we can update)
- Issues requiring physical access to the server
- Denial-of-service attacks
- Social engineering attacks
- Issues in environments running unsupported PHP or MySQL versions
- Self-hosted instances with misconfigured web servers or file permissions

## Response Timeline

| Stage | Commitment |
|---|---|
| Acknowledgement | Within 48 hours of receiving your report |
| Initial assessment | Within 5 business days |
| Fix development | Within 14 business days for critical/high severity |
| Patch release | Within 30 days for critical/high; next scheduled release for medium/low |
| Public disclosure | Coordinated with the reporter, typically 90 days after the fix is released |

## Recognition

We gratefully acknowledge security researchers who report vulnerabilities responsibly. With your permission, we will credit you in the release notes for the patch that addresses your report.

## Supported Versions

Only the latest release of ScoutKeeper receives security updates. Users are strongly encouraged to keep their installations up to date using the built-in auto-update mechanism.
