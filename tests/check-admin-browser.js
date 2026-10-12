// Optional browser checks against HTML produced by render-admin.php.
const fs = require('fs');
const path = require('path');
const assert = require('assert');
const { chromium } = require('playwright');
const refreshFixture = process.env.WNS_TEST_REFRESH_JSON
  ? JSON.parse(fs.readFileSync(process.env.WNS_TEST_REFRESH_JSON, 'utf8'))
  : { success: true, data: {
      html: '<div class="wns-preview-meta"><div class="wns-preview-meta-grid"><div class="wns-preview-meta-item"><div class="wns-preview-meta-label">Content period</div><div class="wns-preview-meta-value">Sep 1 - Sep 30, 2026</div></div></div></div><div class="wns-preview-email-content"></div>',
      email_html: '<html><body><p>Refreshed newsletter</p></body></html>',
    } };
refreshFixture.data.email_html += '<p>Refreshed newsletter</p><script>parent.__previewScriptExecuted = true;</script>';
(async () => {
  const browser = await chromium.launch({
    headless: true,
    ...(process.env.WNS_TEST_BROWSER ? { executablePath: process.env.WNS_TEST_BROWSER } : {}),
  });
  try {
    let tabs = 0;
    for (const file of process.argv.slice(2)) {
      const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/*', route => route.abort());
      await page.evaluate(response => {
        window.ajaxurl = 'https://example.test/wp-admin/admin-ajax.php';
        window.fetch = async () => ({ json: async () => response });
      }, refreshFixture);
      await page.setContent(fs.readFileSync(file, 'utf8'), { waitUntil: 'load' });
      await page.waitForTimeout(200);
      const slovak = await page.locator('input[name="wns_language"][value="sk"]').isChecked();
      assert.deepStrictEqual(errors, [], `${file}: JavaScript errors`);
      assert.equal(await page.locator('.wns-tab-button[aria-current="page"]').count(), 1, 'Current section is announced');
      const missingNames = await page.locator('.wns-tab-content.active input:not([type="hidden"]):not([type="submit"]), .wns-tab-content.active select, .wns-tab-content.active textarea').evaluateAll(elements => elements.filter(element => {
        return !element.labels?.length && !element.getAttribute('aria-label') && !element.getAttribute('aria-labelledby');
      }).map(element => element.name));
      assert.deepStrictEqual(missingNames, [], `${file}: all controls have accessible labels`);
      const brokenDescriptions = await page.locator('[aria-describedby]').evaluateAll(elements => elements.flatMap(element => element.getAttribute('aria-describedby').split(' ').filter(id => !document.getElementById(id))));
      assert.deepStrictEqual(brokenDescriptions, [], 'Contextual descriptions resolve');
      if (path.basename(file) === 'configuration.html') {
        await page.locator('input[name="wns_mail_type"][value="smtp"]').check();
        assert(await page.locator('#wns-smtp-config').isVisible(), 'SMTP fields are revealed when selected');
        await page.locator('input[name="wns_cronjob_enabled"]').check();
        assert(await page.locator('#wns-cronjob-config').isVisible(), 'External schedule fields are revealed when enabled');
      }
      if (path.basename(file) === 'settings.html') {
        const posts = page.getByRole('checkbox', { name: 'Posts', exact: true });
        await posts.focus();
        await page.keyboard.press('Space');
        assert.equal(await posts.isChecked(), false, 'Content types can be deselected by keyboard');
        await page.keyboard.press('Space');
        assert.equal(await posts.isChecked(), true, 'Content types can be selected by keyboard');
        assert.equal(await page.getByRole('checkbox', { name: 'Editor', exact: true }).count(), 1, 'All editable roles are available');
        const fromName = page.locator('#wns-from-name');
        const original = await fromName.inputValue();
        const hasUnsavedWarning = () => page.evaluate(() => {
          const event = new Event('beforeunload', { cancelable: true });
          window.dispatchEvent(event);
          return event.defaultPrevented;
        });
        assert.equal(await hasUnsavedWarning(), false, 'Unchanged form does not warn');
        await fromName.fill('Unsaved edit');
        assert.equal(await hasUnsavedWarning(), true, 'Unsaved edits warn before navigation');
        await fromName.fill(original);
        assert.equal(await hasUnsavedWarning(), false, 'Reverted edits clear the warning');
      }
      if (path.basename(file) === 'preview.html') {
        const initialItemCount = await page.locator('.wns-preview-meta-grid > .wns-preview-meta-item').count();
        assert((await page.locator('#wns-email-preview-content').innerText()).includes('Sep 1 - Sep 30, 2026'));
        assert.equal(await page.locator('#wns-email-preview-content iframe').getAttribute('sandbox'), 'allow-same-origin');
        await page.locator('#wns-refresh-preview').click();
        await page.waitForFunction(() => !document.querySelector('#wns-refresh-preview').disabled);
        assert.equal(await page.locator('.wns-preview-status').innerText(), slovak ? 'Náhľad úspešne obnovený' : 'Preview refreshed successfully');
        const frame = page.locator('#wns-email-preview-content iframe');
        assert.equal(await frame.getAttribute('sandbox'), 'allow-same-origin');
        assert.equal(await frame.evaluate(element => element.contentDocument.body.textContent.includes('Refreshed newsletter')), true);
        assert.equal(await page.evaluate(() => window.__previewScriptExecuted === true), false, 'Preview script must not execute');
        assert.equal(await page.locator('#wns-refresh-preview').isEnabled(), true);
        const previousPreview = await page.locator('#wns-email-preview-content').innerText();
        await page.evaluate(() => { window.__previousPreviewFrame = document.querySelector('#wns-email-preview-content iframe'); });
        for (const failure of ['network', 'server', 'invalid']) {
          await page.evaluate(failure => {
            window.fetch = async () => {
              if (failure === 'network') throw new Error('Offline');
              return { json: async () => failure === 'server' ? { success: false, data: 'Unavailable' } : { success: true, data: { html: '<p>Incomplete</p>', email_html: '' } } };
            };
          }, failure);
          await page.locator('#wns-refresh-preview').click();
          await page.waitForFunction(() => !document.querySelector('#wns-refresh-preview').disabled);
          assert((await page.locator('.wns-preview-status').innerText()).includes(slovak ? 'Skúste to znova' : 'Try again'));
          assert.equal(await page.locator('#wns-email-preview-content').innerText(), previousPreview, 'Failed refresh preserves the previous preview');
          assert(await page.evaluate(() => window.__previousPreviewFrame === document.querySelector('#wns-email-preview-content iframe')), 'Failed refresh keeps the existing email frame');
          assert.equal(await page.locator('#wns-email-preview-content').getAttribute('aria-busy'), null, 'Loading state clears after failure');
          assert.equal(await page.locator('#wns-refresh-preview').isEnabled(), true, 'Failed refresh can be retried');
        }
        if (process.env.WNS_TEST_REFRESH_JSON) {
          assert.equal(await page.locator('.wns-preview-meta-grid > .wns-preview-meta-item').count(), initialItemCount, 'Initial and refreshed details use the same grid');
        }
        for (const width of [1280, 390]) {
          await page.setViewportSize({ width, height: 1000 });
          await page.waitForTimeout(100);
          const layout = await page.evaluate(() => {
            const panel = document.querySelector('.wns-preview-meta').getBoundingClientRect();
            const toolbar = document.querySelector('.wns-preview-toolbar').getBoundingClientRect();
            const button = document.querySelector('#wns-refresh-preview').getBoundingClientRect();
            const columns = getComputedStyle(document.querySelector('.wns-preview-meta-grid')).gridTemplateColumns.split(' ').length;
            const clipped = [...document.querySelectorAll('.wns-preview-meta-value')].some(element => element.scrollWidth > element.clientWidth);
            return { panelTop: panel.top, toolbarBottom: toolbar.bottom, panelLeft: panel.left, panelRight: panel.right, buttonLeft: button.left, buttonRight: button.right, columns, clipped };
          });
          assert(layout.panelTop >= layout.toolbarBottom + 20, 'Toolbar has clear separation from details');
          assert(layout.buttonLeft >= layout.panelLeft && layout.buttonRight <= layout.panelRight + 1, 'Refresh button aligns within preview width');
          assert.equal(layout.clipped, false, 'Preview values do not clip');
          assert.equal(layout.columns, width === 1280 ? 3 : 1, 'Preview columns adapt to viewport');
          assert(await frame.evaluate(element => element.clientHeight >= element.contentDocument.body.scrollHeight - 1), 'Email height follows resized content');
          await page.screenshot({ path: path.join(path.dirname(file), `preview-${width}.png`), fullPage: true });
        }
        await page.screenshot({ path: path.join(path.dirname(file), 'preview.png'), fullPage: true });
      }
      if (path.basename(file) === 'design.html') {
        await page.locator('#header-title').fill('Updated design');
        await page.waitForTimeout(200);
        const frame = page.locator('#email-preview iframe');
        assert.equal(await frame.getAttribute('sandbox'), 'allow-same-origin');
        assert(await frame.evaluate(element => element.contentDocument.body.textContent.includes('Updated design')));
        assert.equal(await frame.evaluate(element => element.contentDocument.body.style.fontFamily.includes('Times New Roman')), true);
      }
      for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 1000 });
        await page.waitForTimeout(100);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
        assert.equal(overflow, false, `${file}: no page overflow at ${width}px`);
        if (path.basename(file) === 'design.html') {
          assert(await page.locator('#email-preview iframe').evaluate(element => element.clientHeight >= element.contentDocument.body.scrollHeight - 1), 'Design preview height follows resized content');
        }
        await page.screenshot({ path: path.join(path.dirname(file), `${path.basename(file, '.html')}-${width}.png`), fullPage: true });
      }
      assert.deepStrictEqual(errors, [], `${file}: JavaScript errors after interaction`);
      await page.close();
      tabs++;
    }
    console.log(`PASS: ${tabs} admin tabs; refreshed/design preview rendering and script sandbox verified.`);
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
