// Prüft den Personen-Picker im Notizen-Modal: Der eingefügte [Name](indi:Xxx)-Link
// muss an der Cursorstelle landen, sichtbar sein und weiteres Tippen überleben —
// mit und ohne aktiven Markdown-Editor.
import { chromium } from 'playwright-core';
import { serve, reporter } from './config.mjs';

const PORT = 8896;
const server = await serve(PORT);
const { check, done } = reporter();
const browser = await chromium.launch();
const ctx = await browser.newContext();

async function rechercheModal(query = '') {
  const page = await ctx.newPage();
  const errs = [];
  page.on('pageerror', e => errs.push(e.message));
  await page.goto(`http://127.0.0.1:${PORT}/${query}`, { waitUntil: 'load' });
  await page.waitForFunction(() => window.__mdeInstalled === true);
  await page.click('#btn-research');
  await page.waitForTimeout(700);
  return { page, errs };
}
const text = page => page.evaluate(() =>
  document.getElementById('ortsregister-notes-textarea').value);

{ // Cursor am Zeilenende, danach übernimmt der Picker den Fokus
  const { page, errs } = await rechercheModal();
  await page.click('#md-ortsregister-notes-textarea');
  await page.keyboard.press('Control+End');
  await page.waitForTimeout(150);
  await page.selectOption('#ortsregister-indi-picker', 'I42');
  await page.waitForTimeout(400);
  const t = await text(page);
  check('Einfügen am Zeilenende, nicht am Textanfang',
        t === '## Recherche\nZeile B ENDE[I42](indi:I42)', t);
  check('  → keine JS-Fehler', errs.length === 0, errs);
  await page.close();
}

{ // Cursor mitten im Text
  const { page } = await rechercheModal();
  await page.click('#md-ortsregister-notes-textarea');
  await page.keyboard.press('Control+End');
  for (let i = 0; i < 5; i++) await page.keyboard.press('ArrowLeft');
  await page.waitForTimeout(150);
  await page.selectOption('#ortsregister-indi-picker', 'I42');
  await page.waitForTimeout(400);
  const t = await text(page);
  check('Einfügen an einer Marke mitten im Text',
        t === '## Recherche\nZeile B[I42](indi:I42) ENDE', t);
  await page.close();
}

{ // nie in den Editor geklickt
  const { page } = await rechercheModal();
  await page.selectOption('#ortsregister-indi-picker', 'I42');
  await page.waitForTimeout(400);
  const t = await text(page);
  check('Ohne vorherigen Klick ans Ende, nicht an den Anfang',
        t === '## Recherche\nZeile B ENDE[I42](indi:I42)', t);
  await page.close();
}

{ // zweimal hintereinander
  const { page } = await rechercheModal();
  await page.click('#md-ortsregister-notes-textarea');
  await page.keyboard.press('Control+End');
  await page.waitForTimeout(150);
  await page.selectOption('#ortsregister-indi-picker', 'I42');
  await page.waitForTimeout(400);
  await page.selectOption('#ortsregister-indi-picker', '');
  await page.selectOption('#ortsregister-indi-picker', 'I42');
  await page.waitForTimeout(400);
  const t = await text(page);
  check('Zweiter Einfügevorgang hängt hinten an',
        t === '## Recherche\nZeile B ENDE[I42](indi:I42)[I42](indi:I42)', t);
  await page.close();
}

{ // Weitertippen und speichern
  const { page } = await rechercheModal();
  await page.click('#md-ortsregister-notes-textarea');
  await page.keyboard.press('Control+End');
  await page.selectOption('#ortsregister-indi-picker', 'I42');
  await page.waitForTimeout(400);
  await page.click('#md-ortsregister-notes-textarea');
  await page.keyboard.press('Control+End');
  await page.keyboard.type(' danach');
  await page.waitForTimeout(300);
  await page.click('#ortsregister-notes-save');
  await page.waitForTimeout(500);
  const saved = await page.evaluate(() => window.__saved.at(-1));
  check('Link überlebt Weitertippen und wird gespeichert',
        saved.markdown === '## Recherche\nZeile B ENDE[I42](indi:I42) danach', saved.markdown);
  await page.close();
}

{ // Editor abgeschaltet: klassischer Textarea-Pfad
  const { page } = await rechercheModal('?nomde=1');
  check('  → Editor ist wirklich aus', await page.evaluate(() =>
    document.getElementById('md-ortsregister-notes-textarea') === null));
  await page.evaluate(() => {
    const ta = document.getElementById('ortsregister-notes-textarea');
    ta.focus(); ta.selectionStart = ta.selectionEnd = 12;   // hinter "## Recherche"
  });
  await page.selectOption('#ortsregister-indi-picker', 'I42');
  await page.waitForTimeout(300);
  const t = await text(page);
  check('Ohne Editor an selectionStart einfügen',
        t === '## Recherche[I42](indi:I42)\nZeile B ENDE', t);
  await page.close();
}

const failed = done();
await browser.close();
server.close();
process.exit(failed ? 1 : 0);
