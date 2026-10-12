# Plugin UI/UX review

Reviewed on 12 October 2026 using Impeccable's audit and polish guidance. Scope: the existing WordPress administration interface, preserving its visual identity and newsletter settings.

## Findings resolved

| Issue | Change |
| --- | --- |
| Standalone fields lacked associated labels and contextual descriptions | Connect labels, input IDs, and help text; identify role and mail-method groups; use section headings and announce the current navigation page |
| Post-type selection depended on clicking custom tags | Replace the custom picker with native labeled checkboxes; preserve the existing settings names and empty-selection behavior |
| Recipient selection exposed only subscriber and administrator roles | Show all editable WordPress roles, including saved custom roles |
| Language radios were removed from keyboard navigation | Keep radios focusable, show focus on their labels, and localize the language-change confirmation |
| Forms could lose unsaved edits during navigation | Warn while settings differ from their initial values; clear the warning after reverting or submitting the edited form |
| Wide padding, fixed preview widths, and non-wrapping navigation caused overflow | Add responsive spacing, wrapping navigation, stacked date fields, and flexible preview dimensions |
| A long demo preview preceded its editing controls on narrower screens | Keep design controls first; place the preview after them when the columns stack |
| Small targets and weak keyboard focus hindered interaction | Improve focus outlines, mobile controls, button/checkbox-label targets, and native checked states |
| Design-preview height did not follow viewport changes | Recalculate frame height after resizing, editing, and logo loading; name the frame for assistive technology |
| Refresh failures discarded the usable email preview | Keep the previous preview while loading and on failure, announce progress and recovery, and re-enable retry |
| Some admin messages stayed in English on Slovak screens | Translate content-type guidance, intro-size guidance, dismissal, language confirmation, and preview status/recovery |
| Notification movement ignored reduced-motion preferences | Disable notification motion and unnecessary hover movement for reduced-motion users |

## Validation

- Offline plugin regression suite: 116 checks passed with OpenSSL and PDO SQLite enabled.
- English and Slovak fixtures: all emitted inline scripts compile.
- Browser checks cover all four tabs, keyboard selection, accessible names/help references, unsaved/reverted edits, expanded configuration fields, and preview recovery.
- Responsive checks cover 1440, 768, 390, and 320 pixel widths, including full-height design previews and no page overflow.
- Sandboxed email frames retain script isolation.
- The Impeccable detector identified accent borders. Admin notification borders were refined; existing quotation styling inside newsletter content was retained because the email template is outside this admin-interface review.

## Verification limits

This review uses rendered PHP fixtures and an isolated Chromium browser. It does not establish full WCAG compliance or replace a check inside a running WordPress installation with its admin styles and other plugins. No real email or external scheduling request was sent.
