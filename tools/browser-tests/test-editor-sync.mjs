// Prüft, dass der visuelle Markdown-Editor und die versteckte Textarea nicht
// auseinanderlaufen. Hintergrund: linkenhancers Knöpfe "Link", "Bild" und
// "Hervorheben" rufen TinyMDEs wrapSelection() ohne fireChange() auf, wodurch
// Änderungen sichtbar sind, aber beim Speichern verlorengehen
// (https://codeberg.org/bschwede/linkenhancer/issues/118).
//
// Wenn hier alle Knöpfe von sich aus "ja" melden, ist das dort behoben und die
// Notbremse in ort-detail.phtml kann raus — dann aber die Mindestversion von
// linkenhancer in README, README.de und im Hinweistext der Einstellungen anheben.
import { chromium } from 'playwright-core';
import { serve, reporter } from './config.mjs';

const PORT = 8897;
const server = await serve(PORT);
const { check, done } = reporter();
const browser = await chromium.launch();
const ctx = await browser.newContext();

const KNOEPFE = ['bold', 'italic', 'Level 1 heading', 'Bulleted list', 'quote',
                 'highlight', 'Insert link', 'Insert image', 'Insert table'];

async function rechercheModal() {
  const page = await ctx.newPage();
  page.on('dialog', d => d.accept(/CnR|Zeilen/i.test(d.message()) ? '2,2' : 'https://example.com/x'));
  await page.goto(`http://127.0.0.1:${PORT}/`, { waitUntil: 'load' });
  await page.waitForFunction(() => window.__mdeInstalled === true);
  await page.click('#btn-research');
  await page.waitForTimeout(650);
  return page;
}
const state = page => page.evaluate(() => ({
  ta: document.getElementById('ortsregister-notes-textarea').value,
  ed: document.getElementById('md-ortsregister-notes-textarea').innerText,
}));
// innerText hängt am letzten Block-Element immer einen Umbruch an; nur dieser
// Unterschied ist unerheblich, alles andere wäre echter Inhaltsverlust.
const gleich = (a, b) => a.replace(/\n+$/, '') === b.replace(/\n+$/, '');

console.log('--- Werkzeugleiste: zieht die Textarea mit? ---');
for (const knopf of KNOEPFE) {
  const page = await rechercheModal();
  await page.click('#md-ortsregister-notes-textarea');
  await page.keyboard.press('Control+End');
  for (let i = 0; i < 4; i++) await page.keyboard.press('Shift+ArrowLeft');
  const vor = await state(page);
  try {
    await page.click(`.TMCommandButton[title^="${knopf}"]`, { timeout: 4000 });
  } catch {
    check(`Knopf "${knopf}" vorhanden`, false, 'nicht gefunden');
    await page.close();
    continue;
  }
  await page.waitForTimeout(650);
  const nach = await state(page);
  check(`${knopf}: Editor geändert und Textarea zieht mit`,
        nach.ed !== vor.ed && gleich(nach.ta, nach.ed), nach);
  await page.close();
}

console.log('\n--- Speichern zieht auch ohne Knopf-Klick nach ---');
{
  // Strg+K löst denselben Befehl aus, umgeht aber den Klick-Handler.
  const page = await rechercheModal();
  const errs = [];
  page.on('pageerror', e => errs.push(e.message));
  await page.click('#md-ortsregister-notes-textarea');
  await page.keyboard.press('Control+End');
  for (let i = 0; i < 4; i++) await page.keyboard.press('Shift+ArrowLeft');
  await page.keyboard.press('Control+k');
  await page.waitForTimeout(600);
  const s = await state(page);
  check('Strg+K erzeugt einen Desync (der Klick-Handler greift hier nicht)', s.ed !== s.ta, s);

  await page.click('#ortsregister-notes-save');
  await page.waitForTimeout(500);
  const saved = (await page.evaluate(() => window.__saved.at(-1))).markdown;
  check('Speichern schickt trotzdem den Editor-Stand', gleich(saved, s.ed), { gespeichert: saved, editor: s.ed });
  check('keine JS-Fehler', errs.length === 0, errs);
  await page.close();
}

const failed = done();
await browser.close();
server.close();
process.exit(failed ? 1 : 0);
