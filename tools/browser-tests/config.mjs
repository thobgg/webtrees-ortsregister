// Gemeinsame Pfade und der kleine Webserver für die Browser-Tests.
//
// Verzeichnisse werden vom Speicherort dieser Datei aus hergeleitet
// (…/modules_v4/ortsregister/tools/browser-tests) und lassen sich per
// Umgebungsvariable überschreiben:
//
//   WEBTREES_ROOT   Wurzel der webtrees-Installation
//   LINKENHANCER    resources-Verzeichnis des linkenhancer-Moduls
//
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const HERE   = path.dirname(fileURLToPath(import.meta.url));
export const MODULE = path.resolve(HERE, '..', '..');                       // …/ortsregister
export const WEBTREES_ROOT = process.env.WEBTREES_ROOT
  ?? path.resolve(MODULE, '..', '..');                                       // …/webtrees
export const LINKENHANCER = process.env.LINKENHANCER
  ?? path.resolve(WEBTREES_ROOT, 'modules_v4', 'linkenhancer', 'resources');
export const PHTML  = path.join(MODULE, 'resources', 'views', 'ort-detail.phtml');
export const PUBLIC = path.join(WEBTREES_ROOT, 'public');
export const BUILD  = path.join(HERE, 'build');                              // erzeugte Testseite

export function checkPrerequisites() {
  const missing = [];
  if (!fs.existsSync(path.join(PUBLIC, 'js', 'vendor.min.js'))) {
    missing.push(`webtrees-Assets nicht gefunden unter ${PUBLIC} — WEBTREES_ROOT setzen`);
  }
  if (!fs.existsSync(path.join(LINKENHANCER, 'js', 'bundle-le-mde.min.js'))) {
    missing.push(`linkenhancer nicht gefunden unter ${LINKENHANCER} — LINKENHANCER setzen`);
  }
  if (missing.length > 0) {
    console.error('Voraussetzungen fehlen:\n  ' + missing.join('\n  '));
    process.exit(2);
  }
}

const TYPES = { '.js': 'text/javascript', '.css': 'text/css', '.html': 'text/html' };

/** Liefert /wt/… aus webtrees/public, /lh/… aus linkenhancer/resources, alles andere aus build/. */
export function serve(port) {
  const server = http.createServer((req, res) => {
    const url = req.url.split('?')[0];
    const file = url.startsWith('/wt/') ? path.join(PUBLIC, url.slice(4))
               : url.startsWith('/lh/') ? path.join(LINKENHANCER, url.slice(4))
               : path.join(BUILD, url === '/' ? 'harness.html' : url);
    try {
      res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] ?? 'application/octet-stream' });
      res.end(fs.readFileSync(file));
    } catch {
      res.writeHead(404);
      res.end('not found: ' + file);
    }
  });
  return new Promise(resolve => server.listen(port, () => resolve(server)));
}

/** Kleiner Zähler für Prüfungen. */
export function reporter() {
  let pass = 0, fail = 0;
  return {
    check(name, ok, detail) {
      ok ? pass++ : fail++;
      console.log(`${ok ? 'OK  ' : 'FAIL'}  ${name}` +
                  (detail !== undefined ? '\n        ' + JSON.stringify(detail) : ''));
    },
    done() {
      console.log(`\n${pass} ok, ${fail} fehlgeschlagen`);
      return fail;
    },
  };
}
