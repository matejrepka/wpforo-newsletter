# Contributing

Thank you for helping improve Weekly Newsletter Sender.

## Before opening an issue

- Search existing issues first.
- Use a current supported WordPress version and confirm the problem with other plugins disabled where practical.
- Do not include passwords, API keys, email lists, database dumps, or other private information.
- Report security vulnerabilities privately using [SECURITY.md](SECURITY.md).

## Pull requests

1. Fork the repository and create a focused branch from the default branch.
2. Keep changes limited to one concern and explain the reason for the change.
3. Follow WordPress PHP coding and security practices.
4. Sanitize input, escape output, verify nonces, and check capabilities for privileged actions.
5. Maintain compatibility with the minimum versions listed in `readme.txt`.
6. Update documentation and `CHANGELOG.md` when behavior changes.
7. Run PHP syntax checks on every PHP file and test the plugin in WordPress before submitting.

Do not commit generated ZIP packages, credentials, local environment files, or backup copies.

## Validation

Run `php tests/regression.php` with OpenSSL and PDO SQLite enabled. This suite uses WordPress API doubles, an in-memory SQL fixture, and simulated mail/API responses; it makes no network requests and sends no real email. See [tests/README.md](tests/README.md) for details. PHP syntax checks must include `includes/`, translations, uninstall, and test files.

Run the staging checks in [docs/REVIEW-1.0.1.md](docs/REVIEW-1.0.1.md) before publishing. Offline checks do not establish live WordPress/wpForo compatibility or inbox delivery. Keep `WNS_VERSION`, the plugin header, `readme.txt`, and release documentation synchronized.

## Licensing

By submitting a contribution, you agree that it may be distributed under the project's GPL-2.0-or-later license.
