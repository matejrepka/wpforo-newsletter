# Regression tests

Requirements: PHP 7.4 or newer with OpenSSL and PDO SQLite enabled.

```sh
php tests/regression.php
```

The suite makes no external requests and sends no real email. It checks credential round trips, tampering, legacy compatibility, double sanitization, settings validation, empty recipient/content selections, custom periods, daylight-saving transitions, nonce rejection, SMTP cleanup, remote-job failure handling, and multisite uninstall cleanup. The actual forum query runs against an in-memory SQLite fixture containing public, restricted, private, unapproved, and out-of-period content.

WordPress APIs, mail delivery, and wpForo permission results are simulated. The HTML sanitization doubles are deliberately minimal and do not validate WordPress's full KSES implementation. The suite is a regression guard, not a substitute for the staging checks in [the review notes](../docs/REVIEW-1.0.1.md).

## Admin rendering and browser checks

With Node.js available, render fixtures and compile every emitted inline script:

```sh
mkdir -p /tmp/wns-admin
php tests/render-admin.php settings /tmp/wns-admin/settings.html
php tests/render-admin.php configuration /tmp/wns-admin/configuration.html
php tests/render-admin.php design /tmp/wns-admin/design.html
php tests/render-admin.php preview /tmp/wns-admin/preview.html
node tests/check-admin-scripts.js /tmp/wns-admin/settings.html /tmp/wns-admin/configuration.html /tmp/wns-admin/design.html /tmp/wns-admin/preview.html
```

On Windows, substitute a writable temporary directory. For optional interaction checks, make Playwright and Chromium available and run `node tests/check-admin-browser.js` with the same HTML paths. The fixture filenames must be `settings.html`, `configuration.html`, `design.html`, and `preview.html` for the tab-specific checks; use those names in a temporary directory. Set `WNS_TEST_BROWSER` to an existing Chromium executable if needed. The test blocks network access, mocks refresh responses, verifies all four tabs have no JavaScript errors, tests design/refresh previews, and confirms preview scripts cannot execute. A preview screenshot is written alongside the fixture.

The browser checks also cover accessible field names and help references, current-section navigation, keyboard selection of content types, all editable recipient roles, unsaved/reverted edits, SMTP and external-schedule disclosure, and preservation of the previous preview after network, server, and malformed-response failures. Every tab is checked for horizontal overflow at 1440, 768, 390, and 320 pixels; design previews must resize their height with the viewport. Screenshots for each tab and width are written alongside the fixtures.

To repeat in Slovak, render into a separate directory using an empty third argument and `sk` as the fourth argument, for example `php tests/render-admin.php preview /tmp/wns-admin-sk/preview.html "" sk`. Keep the same fixture filenames and pass those paths to the browser checker. These offline fixtures do not include WordPress's full admin styles or validate actual email/API delivery.

Pass a third argument to `render-admin.php` (for example `/tmp/wns-admin/refresh.json`) to capture the actual preview AJAX response using the test API doubles. Set `WNS_TEST_REFRESH_JSON` to that path when running browser checks to verify the same details markup before and after refresh. Layout checks cover desktop/mobile columns, toolbar separation, clipped text, and email-frame resizing.
