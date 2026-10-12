# Review and maintenance update: 1.0.1

Review date: 2026-10-12. Previous version: 1.0.0. Updated version: 1.0.1.

## Findings and fixes

| Finding | Impact | Fix |
| --- | --- | --- |
| Raw wpForo SQL did not check moderation, privacy, or forum permissions | Private/restricted content could enter a shared digest | Filter approved, non-private posts/topics and require Guest forum/topic/reply access; use active-board tables; skip unavailable wpForo |
| Published WordPress posts could still have passwords or belong to non-public types | Protected content could enter excerpts | Exclude password-protected posts and constrain selected types to public types |
| Language values were interpolated into an include path | An invalid stored/admin-supplied setting could include unintended PHP files | Allow only `en` and `sk` before constructing paths |
| Test sends and notice dismissals lacked nonce checks | Cross-site requests could cause administrator actions | Verify nonces alongside administrator capabilities; validate test addresses |
| Settings and legacy CSS values were insufficiently validated | Malformed settings could break rendering, scheduling, or inject markup | Typed validation, enum lists, bounded numeric CSS, safe colors, and read-time CSS validation |
| Previews used unrestricted same-origin frames; refresh inserted scripts through `innerHTML` | Preview HTML could execute scripts, while refreshed frames could remain blank | Sandbox frames without script permission; pass email HTML separately and mount frames explicitly |
| Credential encryption was unauthenticated and new options could be sanitized twice | Ciphertext could be modified undetected; credentials could become unusable | AES-256-GCM for new secrets, strict decoding, idempotent sanitization, legacy CBC read support |
| Empty role selections were passed to an unrestricted user query | Mail could target every user | Return no recipients for empty/invalid selections; validate/deduplicate addresses |
| Empty content-type selections reverted to posts | Deselected content remained included | Return no WordPress content when the selection is empty |
| Preview refresh always used weekly dates | Refreshed preview disagreed with custom-range sending | Share period calculation and reject invalid/reversed custom ranges |
| Fixed-second weekly events and UTC external jobs drifted across DST | Send time could change by an hour | Recalculate single WordPress events in the site timezone; use named external timezones |
| SMTP removed every mail initialization callback and left reused mailer state behind | Other plugins lost hooks; newsletter credentials could affect later mail | Remove only the newsletter callback and restore mailer state in `finally` |
| Missing SMTP configuration silently fell back to another transport | Delivery could use an unintended mail route | Fail SMTP delivery explicitly; support non-email login names and explicit encryption choices |
| Manual sending always reported success | Disabled/empty/partial failures were hidden | Return transport acceptance counts and accurate status |
| Remote cron method value was POST; synchronization ran before all settings were saved; any update error caused recreation | Wrong request method, stale configuration, duplicate/lost remote jobs | Use GET (`0`), defer settings synchronization, and recreate only after 404 |
| Uninstall omitted credentials/configuration and touched unrelated uploads/cache | Sensitive data remained, or unrelated data could be removed | Complete per-site cleanup, attempt remote deletion on deactivation, and preserve uploads/unrelated caches |

## Validation

The offline regression suite passed 116 checks on PHP 7.4.33 with OpenSSL and PDO SQLite. All nine plugin/test PHP files passed syntax checks. The forum SQL is executed against an in-memory fixture; other WordPress/wpForo behavior is simulated. Mail and HTTP calls are intercepted.

All 25 inline scripts emitted by the four admin-tab fixtures compiled. Chromium interaction checks passed on all four tabs, including custom-period preview refresh, live design changes, quoted font families, and blocked script execution in sandboxed preview frames. The checks also identified and fixed missing refresh controls/status elements and removed an obsolete hidden preview block.

Implementation details were checked against the official wpForo release source (`Permissions::forum_can`, Guest group 4, board table names, and topic/post privacy/status fields), [WordPress mail hooks](https://developer.wordpress.org/reference/hooks/phpmailer_init/), [WordPress scheduling](https://developer.wordpress.org/reference/functions/wp_schedule_single_event/), and the [cron-job.org API](https://docs.cron-job.org/rest-api.html).

No running WordPress installation, live SMTP account, or real cron-job.org account was used. Fixtures do not include WordPress's complete admin environment; live compatibility and actual delivery require staging verification.

## Staging checks before publication

1. Install the complete plugin on the minimum supported WordPress/PHP versions and a current supported configuration. Confirm a fresh install is disabled and an upgrade retains existing settings.
2. Add public and password-protected WordPress posts and a public custom post type. Confirm only eligible content appears, including when roles/types are cleared.
3. With wpForo enabled, create public, restricted, private, and unapproved content, including replies. Confirm previews and messages contain only approved Guest-visible content. Repeat with wpForo disabled and on the intended board.
4. Set a custom date range, open Preview, and refresh. Check the date range and rendered iframe agree. Test invalid/reversed dates, logo/header/footer changes, quoted font names, and blocked script execution.
5. Send a test email with valid credentials. Test invalid SMTP credentials, a non-email SMTP username, and another plugin using `phpmailer_init`. Confirm unrelated mail retains its configuration and hooks.
6. Confirm nonce-less or non-admin test/dismissal requests are rejected. Check successful and partially failed manual sends show accurate results.
7. Check the next scheduled local time before/after DST and after changing WordPress timezone/day/time. Trigger WP-Cron and verify the following event exists.
8. In a test cron-job.org account, verify GET, the callback URL, named timezone, midnight rollover, first API-key save, settings synchronization, and failure/retry behavior. Disable/deactivate and confirm remote deletion; manually remove any job left after a failed deletion.
9. Uninstall on single-site and multisite staging. Confirm plugin options/credentials/events are removed and unrelated uploads/caches remain.

## Upgrade considerations

Existing CBC-encrypted credentials are readable but remain in the old format until replaced and saved. Salt rotation invalidates stored credentials. Shared digests deliberately exclude restricted content for every audience. Third-party membership restrictions on otherwise public WordPress content require separate testing. Recipient consent, unsubscribe workflows, large-list batching, and guaranteed exactly-once delivery are outside this maintenance update.
