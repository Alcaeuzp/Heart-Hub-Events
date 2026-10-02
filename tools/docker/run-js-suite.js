'use strict';
const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const report = process.argv[2] || '/results/javascript.json';
const results = [];
function run(kind, file, args) {
  const started = performance.now();
  const result = spawnSync(process.execPath, args, {encoding: 'utf8'});
  const output = (result.stdout || '') + (result.stderr || '') + (result.error ? String(result.error) : '');
  const assertions = (output.match(/^PASS[: ]/gm) || []).length;
  const failed = result.status !== 0 || /^SKIP\b/m.test(output) || (kind === 'JavaScript fixture' && !assertions);
  results.push({kind, file: path.relative(root, file), status: failed ? 'FAIL' : 'PASS', exit_code: result.status, assertions, duration_ms: Math.round(performance.now()-started), output});
  console.log(`${failed ? 'FAIL' : 'PASS'} ${kind}: ${path.relative(root,file)}`);
  if (failed) console.log(output);
}
function files(directory) {
  return fs.readdirSync(directory, {withFileTypes:true}).flatMap(entry => entry.isDirectory() ? files(path.join(directory,entry.name)) : entry.name.endsWith('.js') ? [path.join(directory,entry.name)] : []);
}
for (const file of files(path.join(root,'assets')).sort()) run('JavaScript syntax',file,['--check',file]);
for (const name of fs.readdirSync(path.join(root,'tests')).filter(name=>name.endsWith('.js')).sort()) {
  const file = path.join(root,'tests',name);
  run('JavaScript fixture',file,[file]);
}
const summary = {node:process.version, checks:results.length, assertions:results.reduce((sum,r)=>sum+r.assertions,0), failed:results.filter(r=>r.status!=='PASS').length, results};
fs.writeFileSync(report,JSON.stringify(summary,null,2)+'\n');
console.log(`Node ${process.version}: ${summary.checks} checks, ${summary.assertions} assertions, ${summary.failed} failures/skips.`);
process.exitCode = summary.failed ? 1 : 0;
