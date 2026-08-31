import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
const dir=path.resolve(process.argv[2]);
const j=n=>JSON.parse(fs.readFileSync(path.join(dir,n+'.json'),'utf8'));
const s=j('summary'),ps=j('packages'),fr=j('file-roster'),map=j('public-surface'),syms=j('symbols'),ep=j('entrypoints');
const checks=[];
function check(name,fn){fn();checks.push(name);}
check('every tracked path has exactly one A1-A6 allocation',()=>{assert.equal(fr.length,s.tracked_files);assert.equal(new Set(fr.map(x=>x.path)).size,fr.length);assert.ok(fr.every(x=>/^A[1-6]$/.test(x.primary_audit)));});
check('all packages are assigned and have hashed manifests',()=>{assert.equal(ps.length,s.package_directories);assert.equal(new Set(ps.map(x=>x.directory)).size,ps.length);assert.ok(ps.every(x=>/^A[1-6]$/.test(x.primary_audit)&&/^[a-f0-9]{64}$/.test(x.manifest_sha256)));});
check('PHP, meta and JS denominators reconcile',()=>{assert.equal(s.php_libraries+s.metapackages+s.javascript_packages,s.package_directories);assert.equal(s.php_libraries+s.metapackages,s.composer_packages);});
check('package source counters match tracked source roster',()=>assert.equal(ps.reduce((n,x)=>n+x.source_php_files,0),s.source_php_files));
check('every public-map entry resolves to a tokenized declaration',()=>{assert.equal(map.length,s.public_map_entries);assert.ok(map.every(x=>x.source_candidates.length>0));});
check('manifest providers resolve to named declarations',()=>{const missing=ep.filter(x=>x.kind==='manifest_provider'&&!syms.some(y=>y.symbol===x.symbol));assert.deepEqual(missing,[]);});
check('every internal dependency resolves to a local manifest',()=>assert.ok(j('dependencies').every(x=>x.resolves_locally)));
check('no library is silently omitted from executable layer map',()=>assert.equal(s.layer_map_gaps.length,0));
console.log(JSON.stringify({status:'pass',checks,baseline:s.head,note:'Inventory consistency only, not behavioral or architectural correctness.'},null,2));
