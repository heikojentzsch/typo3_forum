import { build } from 'esbuild';
import { cp, mkdir, readFile, writeFile } from 'node:fs/promises';
const out = '../../Public/Editor';
await mkdir(out, { recursive: true });
await build({ entryPoints: ['editor.js'], bundle: true, format: 'esm', minify: true, outdir: out, entryNames: '[name]', target: ['es2022'], legalComments: 'linked' });
await cp('node_modules/tinymce', `${out}/tinymce`, { recursive: true, filter: path => !path.endsWith('.map') && !path.endsWith('.ts') && !path.includes('/bin/') });
const readData = async locale => JSON.parse(await readFile(`node_modules/emojibase-data/${locale}/data.json`, 'utf8'));
const flatten = rows => rows.flatMap(row => [row, ...(row.skins || []).map(skin => ({ ...skin, tags: row.tags }))]);
const english = flatten(await readData('en'));
const german = new Map(flatten(await readData('de')).map(row => [row.hexcode, row]));
// Exclude ungrouped regional-indicator components, retain every catalogued emoji and skin variant.
const emojis = english.filter(row => Number.isInteger(row.group)).sort((a, b) => a.order - b.order).map(row => {
    const de = german.get(row.hexcode) || row;
    return { emoji: row.emoji, en: row.label, de: de.label, group: row.group,
        search: [...new Set([row.label, de.label, ...(row.tags || []), ...(de.tags || []), row.hexcode, row.emoji])].join(' ') };
});
await writeFile(`${out}/emojis.json`, JSON.stringify(emojis) + '\n');
await cp('node_modules/emojibase-data/LICENSE', `${out}/EMOJIBASE-LICENSE.txt`);
await writeFile(`${out}/VERSIONS.json`, JSON.stringify({ tinymce: '8.9.3', 'emojibase-data': '17.0.0', emojiCount: emojis.length }, null, 2) + '\n');
