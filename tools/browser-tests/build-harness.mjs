// Erzeugt build/harness.html: Modal-Markup nachgebaut, das JavaScript aber 1:1
// aus resources/views/ort-detail.phtml extrahiert — damit die Tests den echten
// ausgelieferten Code prüfen und nicht eine Abschrift davon.
import fs from 'node:fs';
import path from 'node:path';
import { PHTML, BUILD, checkPrerequisites } from './config.mjs';

checkPrerequisites();

const src = fs.readFileSync(PHTML, 'utf8').split('\n');

// Der Notizen-Modal-Block ist das erste <script> nach der Textarea.
const anchor = src.findIndex(l => l.includes('id="ortsregister-notes-textarea"'));
if (anchor < 0) throw new Error('Notizen-Textarea in ' + PHTML + ' nicht gefunden');
const start = src.findIndex((l, i) => i > anchor && l.trim() === '<script>');
const end   = src.findIndex((l, i) => i > start  && l.trim() === '</script>');
if (start < 0 || end < 0) throw new Error('script-Block nicht gefunden');

const realJs = src.slice(start + 1, end).join('\n');
if (/<\?php|<\?=/.test(realJs)) throw new Error('PHP im extrahierten JS-Block — Extraktion prüfen');
for (const needle of ['insertAtCursor', 'savedRange', 'ortsregisterSyncMarkdownEditors']) {
  if (!realJs.includes(needle)) throw new Error('fehlt im Quelltext: ' + needle);
}

const html = `<!doctype html>
<html lang="de"><head><meta charset="utf-8"><title>ortsregister — Notizen-Modal</title>
<link rel="stylesheet" href="/wt/css/vendor.min.css">
<link rel="stylesheet" href="/lh/css/bundle-le-mde.min.css"></head><body>

<button type="button" class="ortsregister-notes-edit-btn" id="btn-notes"
        data-filename="notes.md" data-title="Beschreibung" data-placeholder="Beschreibung des Ortes…"
        data-markdown="# Beschreibung&#10;Text A" data-mtime="111" data-person-picker="0">B</button>
<button type="button" class="ortsregister-notes-edit-btn" id="btn-research"
        data-filename="recherche.md" data-title="Recherche" data-placeholder="Rechercheprotokoll…"
        data-markdown="## Recherche&#10;Zeile B ENDE" data-mtime="222" data-person-picker="1">R</button>
<div data-notes-display="notes.md">alt A</div>
<div data-notes-display="recherche.md">alt B</div>

<div class="modal fade" id="ortsregister-notes-modal" tabindex="-1" aria-hidden="true"
     data-save-url="/save" data-toggle-url="/toggle" data-csrf="CSRF" data-folder-root="/root" data-place-name="Testort">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><span id="ortsregister-notes-title-prefix">Notizen bearbeiten</span></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <textarea id="ortsregister-notes-textarea" class="form-control" rows="16"></textarea>
      <input type="hidden" id="ortsregister-notes-mtime" value="0">
      <input type="hidden" id="ortsregister-notes-filename" value="notes.md">
      <div id="ortsregister-indi-picker-wrap">
        <select id="ortsregister-indi-picker"><option value=""></option><option value="I42">Anna Müller, 1850-1923</option></select>
      </div>
      <div id="ortsregister-notes-filehint">
        <span id="ortsregister-notes-hint-loc" style="display:none">LOC</span>
        <span id="ortsregister-notes-hint-file" style="display:none"><span id="ortsregister-notes-fileecho">notes.md</span></span>
      </div>
      <div id="ortsregister-notes-status"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
      <button type="button" class="btn btn-primary" id="ortsregister-notes-save">Speichern</button></div>
  </div></div>
</div>

<script src="/wt/js/vendor.min.js"></script>
<script src="/lh/js/bundle-le-mde.min.js"></script>
<script>
// Speichern abfangen statt an den Server zu schicken.
window.__saved = [];
window.fetch = function (url, opts) {
  const out = {}; for (const [k, v] of opts.body.entries()) out[k] = v;
  window.__saved.push(out);
  return Promise.resolve({ json: () => Promise.resolve({ success: true, mtime: 999, markdown: out.markdown, html: '<p>ok</p>' }) });
};
</script>

<script>
${realJs}
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // ?nomde=1 lässt den Editor weg — für den Fall "Einstellung ausgeschaltet".
  if (location.search.includes('nomde')) { window.__mdeInstalled = true; return; }
  LinkEnhMod.installMDE({
    I18N: {},
    query_filter: "textarea[id=ortsregister-notes-textarea], textarea[id=ortsregister-kb-log-textarea]",
    helpmd_url: '/help'
  });
  window.__mdeInstalled = true;
});
</script>
</body></html>`;

fs.mkdirSync(BUILD, { recursive: true });
fs.writeFileSync(path.join(BUILD, 'harness.html'), html);
console.log(`harness.html gebaut — JS aus ${path.basename(PHTML)} Zeilen ${start + 2}..${end} (${realJs.split('\n').length} Zeilen)`);
