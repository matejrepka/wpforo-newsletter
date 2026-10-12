// Compile every inline script emitted by the PHP admin renderer.
const fs = require('fs');
const vm = require('vm');
let checked = 0;
for (const file of process.argv.slice(2)) {
  const html = fs.readFileSync(file, 'utf8');
  for (const match of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)) {
    new vm.Script(match[1], { filename: file + ':script-' + checked++ });
  }
}
console.log(`PASS: ${checked} rendered admin scripts compile.`);
