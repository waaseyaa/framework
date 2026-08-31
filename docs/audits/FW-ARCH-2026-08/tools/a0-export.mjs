import fs from 'node:fs';
import path from 'node:path';

// Export the reviewable census subset; larger lexical/token datasets are regenerated.
if (process.argv.length !== 4) throw Error('Usage: node a0-export.mjs <generated-census> <data-directory>');
const input = path.resolve(process.argv[2]);
const output = path.resolve(process.argv[3]);
if (input === output) throw Error('Use a separate destination');
const names = ['summary', 'packages', 'dependencies', 'profiles', 'file-roster', 'public-surface', 'entrypoints', 'support-surfaces'];
fs.mkdirSync(output, {recursive: true});
for (const name of names) {
  const data = JSON.parse(fs.readFileSync(path.join(input, `${name}.json`), 'utf8'));
  const text = Array.isArray(data)
    ? '[\n' + data.map(row => '  ' + JSON.stringify(row)).join(',\n') + '\n]\n'
    : JSON.stringify(data, null, 2) + '\n';
  fs.writeFileSync(path.join(output, `${name}.json`), text);
}
console.log(JSON.stringify({exported: names, note: 'Generated inventory; no behavioral certification.'}));
