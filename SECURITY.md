# Security Policy

## Supported versions

Security fixes are provided for the latest released version.

| Version | Supported |
| --- | --- |
| 1.0.2 | Yes |
| 1.0.1 | Update to 1.0.2 |
| 1.0.0 | Upgrade to 1.0.2 |
| Earlier or unreleased snapshots | No |

## Reporting a vulnerability

Please use GitHub's private vulnerability reporting feature for this repository. If that feature is unavailable, contact the maintainer through the contact details at [marep.sk](https://marep.sk/).

Include the affected version, reproduction steps, impact, and any proposed mitigation. Do not include real credentials or personal data. Please allow a reasonable period for investigation and remediation before public disclosure.

Public GitHub issues are appropriate for ordinary bugs, but not for suspected vulnerabilities.

## Security behavior in 1.0.1

Shared newsletters contain approved wpForo content visible to the Guest group, and published WordPress content from selected public post types with no post password. Recipient roles select the audience; they do not authorize disclosure of private forum content. Content restrictions introduced by third-party WordPress membership plugins need separate staging verification.

Administrative send/test/settings actions require administrator capabilities and WordPress request protection. Preview documents are sandboxed without script execution. Language files and CSS settings are constrained to supported values.

New database credentials use AES-256-GCM authenticated encryption. Previously saved AES-CBC credentials are accepted for upgrade compatibility; replace and save them to use the authenticated format. Encryption depends on the secrecy and stability of WordPress salts and does not protect a compromised WordPress administrator or server.

SMTP configuration errors do not fall back to another transport. The plugin preserves unrelated mail hooks and restores mailer configuration after its own sends.

See [the 1.0.1 review notes](docs/REVIEW-1.0.1.md) for the review scope and validation limitations.
