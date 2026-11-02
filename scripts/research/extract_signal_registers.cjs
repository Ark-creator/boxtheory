// Extract these ReportLab/Helvetica signal registers without third-party packages.
// This deliberately does not claim to support arbitrary PDFs, OCR, or custom fonts.
const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');
const crypto = require('node:crypto');

function ascii85(input) {
  const chars = input.trim().replace(/^<~/, '').replace(/~>$/, '').replace(/\s/g, '');
  const bytes = [];
  let group = [];
  const unpack = values => {
    const v = values.reduce((a, b) => a * 85 + b, 0);
    if (v > 0xffffffff) throw new Error('Invalid ASCII85 group.');
    return [(v >>> 24) & 255, (v >>> 16) & 255, (v >>> 8) & 255, v & 255];
  };
  for (const ch of chars) {
    if (ch === 'z' && !group.length) { bytes.push(0, 0, 0, 0); continue; }
    const n = ch.charCodeAt(0) - 33;
    if (n < 0 || n > 84) throw new Error('Invalid ASCII85 character.');
    group.push(n);
    if (group.length === 5) { bytes.push(...unpack(group)); group = []; }
  }
  if (group.length === 1) throw new Error('Truncated ASCII85 group.');
  if (group.length) {
    const count = group.length - 1;
    while (group.length < 5) group.push(84);
    bytes.push(...unpack(group).slice(0, count));
  }
  return Buffer.from(bytes);
}

function extract(file) {
  const bytes = fs.readFileSync(file);
  const raw = bytes.toString('latin1');
  if (!raw.startsWith('%PDF-') || !raw.includes('ReportLab Generated PDF') || raw.includes('/ToUnicode')) {
    throw new Error(`${file}: expected the supplied ReportLab registers with standard Helvetica text.`);
  }
  const objects = new Map([...raw.matchAll(/^(\d+) 0 obj\r?\n([\s\S]*?)\r?\nendobj/gm)].map(m => [m[1], m[2]]));
  const pages = [];
  for (const object of objects.values()) {
    if (!/\/Type\s*\/Page\b/.test(object)) continue;
    const ref = /\/Contents\s+(\d+) 0 R/.exec(object)?.[1];
    const content = objects.get(ref);
    const streamAt = content && /\bstream\r?\n/.exec(content);
    if (!streamAt) throw new Error(`${file}: unsupported page content.`);
    const dictionary = content.slice(0, streamAt.index);
    const length = Number(/\/Length\s+(\d+)\b/.exec(dictionary)?.[1]);
    let data = Buffer.from(content.slice(streamAt.index + streamAt[0].length, streamAt.index + streamAt[0].length + length), 'latin1');
    if (!length || !dictionary.includes('/ASCII85Decode') || !dictionary.includes('/FlateDecode')) {
      throw new Error(`${file}: unsupported stream encoding.`);
    }
    data = zlib.inflateSync(ascii85(data.toString('latin1')));
    const text = [...data.toString('latin1').matchAll(/\(((?:\\[\s\S]|[^\\)])*)\)\s*Tj/g)]
      .map(m => m[1].replace(/\\([0-7]{1,3}|[nrtbf()\\])/g, (_, v) => /^[0-7]/.test(v)
        ? String.fromCharCode(parseInt(v, 8)) : ({ n: '\n', r: '\r', t: '\t', b: '\b', f: '\f' }[v] ?? v)))
      .join('\n');
    if (!text) throw new Error(`${file}: empty page extraction.`);
    pages.push(text);
  }
  if (!pages.length) throw new Error(`${file}: no readable pages.`);
  return {
    text: pages.map((s, i) => `--- PAGE ${i + 1} ---\n${s}`).join('\n\n'),
    provenance: { file: path.basename(file), bytes: bytes.length, sha256: crypto.createHash('sha256').update(bytes).digest('hex'), pages: pages.length },
  };
}

const args = process.argv.slice(2);
if (args[0] !== '--output-dir' || args.length < 3) {
  console.error('Usage: node scripts/research/extract_signal_registers.cjs --output-dir <directory> <pdf> [<pdf> ...]');
  process.exit(1);
}
const output = path.resolve(args[1]);
fs.mkdirSync(output, { recursive: true });
const manifest = args.slice(2).map(file => {
  const result = extract(file);
  fs.writeFileSync(path.join(output, path.basename(file, '.pdf') + '.txt'), result.text + '\n');
  return result.provenance;
});
fs.writeFileSync(path.join(output, 'source-manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log(JSON.stringify(manifest, null, 2));
