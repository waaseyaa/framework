import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

// Read-only source census. Generated output is deliberately outside the checkout.
if(process.argv.length !== 4) throw Error('Usage: node a0-inventory.mjs <clean-source-checkout> <external-output-directory>');
const root=fs.realpathSync(path.resolve(process.argv[2]));
const out=path.resolve(process.argv[3] || '');
if(!fs.existsSync(path.join(root,'bin/git')) || out===root || out.startsWith(root+path.sep)) throw Error('Use a framework checkout and an external output directory');
const bash=process.env.A0_BASH || (process.platform==='win32'?'C:/Program Files/Git/bin/bash.exe':'bash');
const git=(...args)=>execFileSync(bash,['bin/git',...args],{cwd:root,encoding:'utf8',maxBuffer:64*1024*1024});
const files=git('ls-files','-z').split('\0').filter(Boolean).sort();
const read=f=>fs.readFileSync(path.join(root,f),'utf8').replaceAll('\r\n','\n');
const sha=f=>crypto.createHash('sha256').update(fs.readFileSync(path.join(root,f))).digest('hex');
const json=f=>JSON.parse(read(f));
const has=f=>files.includes(f);
const head=git('rev-parse','HEAD').trim();
if(git('status','--porcelain').trim()) throw Error('Baseline must be clean');
const assignment={
 A1:'database-legacy',
 A2:'access audit auth entity entity-storage field user typed-data validation oidc oauth-provider node taxonomy relationship groups',
 A3:'foundation cache config error-handler http-client i18n mail mercure plugin queue scheduler state notification frankenphp workspace cli',
 A4:'admin admin-surface ai-agent ai-observability ai-pipeline ai-schema ai-tools ai-vector analytics api attachment billing bimaaji engagement genealogy geo github graphql inertia ingestion listing mcp media menu messaging migration note page-builder path publishing routing search seo ssr structured-import wayfinding workflows',
 A5:'deployer',
 A6:'cms core full debug telescope testing site-contract'
};
const owners={};for(const [lane,names] of Object.entries(assignment))for(const name of names.split(' ')){if(owners[name])throw Error('Duplicate package '+name);owners[name]=lane;}
function owner(f){
 if(/^packages\/foundation\/(src\/(Schema|Migration)\/)/.test(f))return 'A1';
 if(/^packages\/foundation\/(resources\/upgrade|src\/Upgrade)\//.test(f))return 'A5';
 if(/^packages\/foundation\/src\/(Audit|Security)\//.test(f))return 'A2';
 if(/^packages\/foundation\/src\/(Http|Ingestion)\//.test(f))return 'A4';
 if(/^packages\/entity-storage\/src\/(Schema\/|EntitySchemaTableMaterializer|SqlSchemaHandler)/.test(f))return 'A1';
 if(/^packages\/cli\/src\/.*(Migrate|Migration|Schema|DbInit)/.test(f))return 'A1';
 const p=f.match(/^packages\/([^/]+)\//)?.[1];if(p){if(!owners[p])throw Error('Unassigned package '+p);return owners[p];}
 if(/^(config|defaults)\//.test(f))return 'A3';
 if(/^public\//.test(f))return 'A4';
 return 'A6';
}
const layerSource=read('bin/check-package-layers').match(/\$layerByShort\s*=\s*\[([\s\S]*?)\];/)?.[1];
if(!layerSource)throw Error('Layer-map syntax changed');
const layers=Object.fromEntries([...layerSource.matchAll(/'([^']+)'\s*=>\s*(\d+)/g)].map(m=>[m[1],Number(m[2])]));
const tableLayers=Object.fromEntries([...read('docs/specs/extension-compatibility-matrix.md').matchAll(/^\| ([a-z][a-z0-9-]+) \| ([0-6]) \|/gm)].map(m=>[m[1],Number(m[2])]));
const directories=[...new Set(files.filter(f=>f.startsWith('packages/')).map(f=>f.split('/')[1]))].sort();
const packages=directories.map(name=>{
 const prefix='packages/'+name+'/',mf=prefix+'composer.json',jf=prefix+'package.json';
 const m=has(mf)?json(mf):null,j=has(jf)?json(jf):null,pf=files.filter(f=>f.startsWith(prefix));
 const dependencies=Object.fromEntries(['require','require-dev','suggest','replace','provide'].map(k=>[k,Object.entries(m?.[k]||{}).filter(([n])=>n.startsWith('waaseyaa/')).map(([n,v])=>({name:n,constraint:v}))]));
 const cross={foundation:['A1','A2','A4','A5'],'entity-storage':['A1','A5'],auth:['A1','A3'],cli:['A1','A4'],deployer:['A1','A2'],cache:['A2'],queue:['A2'],media:['A2'],attachment:['A2'],mcp:['A2','A3'],'ai-agent':['A2','A3'],api:['A2','A3']};
 return {directory:name,name:m?.name||j?.name||name,kind:m?.type|| (j?'javascript':'unclassified'),primary_audit:owners[name],cross_audits:['A6',...(cross[name]||[])].filter((x,i,a)=>x!==owners[name]&&a.indexOf(x)===i),layer:layers[name]??null,documented_layer:tableLayers[name]??null,manifest:m?mf:j?jf:null,manifest_sha256:sha(m?mf:jf),description:m?.description||j?.description||null,php:m?.require?.php||null,autoload:m?.autoload||{},autoload_dev:m?.['autoload-dev']||{},extra:m?.extra||{},bin:m?.bin||[],scripts:m?.scripts||j?.scripts||{},dependencies,file_count:pf.length,source_php_files:pf.filter(f=>/^packages\/[^/]+\/src\/.*\.php$/.test(f)).length,test_php_files:pf.filter(f=>/\/(tests|testing)\/.*\.php$/.test(f)).length,readme:has(prefix+'README.md'),review_status:'inventoried_not_behaviorally_audited'};
});
const names=new Map(packages.map(p=>[p.name,p]));
const rootManifest=json('composer.json');
const edges=[];for(const p of packages)for(const [scope,deps]of Object.entries(p.dependencies))for(const d of deps)edges.push({from:p.name,to:d.name,scope,constraint:d.constraint,resolves_locally:names.has(d.name)});
for(const p of packages){p.runtime_inbound=edges.filter(e=>e.scope==='require'&&e.to===p.name).length;p.runtime_outbound=p.dependencies.require.length;}
function closure(name){const seen=new Set();function visit(n){if(seen.has(n))return;seen.add(n);for(const d of names.get(n)?.dependencies.require||[])visit(d.name);}visit(name);return [...seen].sort();}
const profiles=Object.fromEntries(['waaseyaa/core','waaseyaa/cms','waaseyaa/full'].map(n=>[n,closure(n)]));
const rootClosure=new Set();for(const n of Object.keys(rootManifest.require||{}).filter(n=>n.startsWith('waaseyaa/')))for(const p of closure(n))rootClosure.add(p);profiles['waaseyaa/framework']=[...rootClosure].sort();
for(const p of packages)p.runtime_profiles=Object.entries(profiles).filter(([,ns])=>ns.includes(p.name)).map(([n])=>n);
const fileRoster=files.map(f=>({path:f,primary_audit:owner(f),category:f.startsWith('kitty-specs/')||f.startsWith('docs/history/')?'historical':/\/(tests|testing)\//.test(f)||f.startsWith('tests/')?'test':/\/dist\//.test(f)?'generated':f.startsWith('docs/')?'documentation':'source_or_support'}));
const sources=files.filter(f=>/^packages\/[^/]+\/src\/.*\.php$/.test(f));
const declarationFiles=files.filter(f=>/^packages\/[^/]+\/(src|tests|testing)\/.*\.php$/.test(f));
const symbols=JSON.parse(execFileSync('php',[path.join(path.dirname(fileURLToPath(import.meta.url)),'a0-php-symbols.php'),root],{input:JSON.stringify(declarationFiles),encoding:'utf8',maxBuffer:32*1024*1024}));
const publicMap=[...read('docs/public-surface-map.php').matchAll(/^\s*'([^']+)'\s*=>\s*'(public|internal|extract|remove)'/gm)].map(m=>({symbol:m[1].replaceAll('\\\\','\\'),disposition:m[2]}));
const namespaces=packages.flatMap(p=>Object.entries(p.autoload['psr-4']||{}).map(([ns,dir])=>({ns,dir,package:p}))).sort((a,b)=>b.ns.length-a.ns.length);
for(const row of publicMap){const n=namespaces.find(n=>row.symbol.startsWith(n.ns));row.package=n?.package.name||null;row.primary_audit=n?.package.primary_audit||'A6';row.source_candidates=symbols.filter(s=>s.symbol===row.symbol).map(s=>s.path);if(row.source_candidates[0])row.primary_audit=owner(row.source_candidates[0]);row.status='declared_disposition_not_verified_contract';row.declared_outside_src=row.source_candidates.some(p=>!p.includes('/src/'));}
const entrypoints=[],signals=[],apiTagged=[];
const patterns={environment_read:/\bgetenv\s*\(|\$_ENV\b|\$_SERVER\b/,broad_catch:/catch\s*\([^)]*(?:Throwable|\\Exception\b)/,fallback_candidate:/\?\?\s*(?:new\s+Null|false|true|\[\]|null|'[^']*')|\bnew\s+Null\w+/,normalization_candidate:/function\s+\w*(?:normaliz|canonical|compatib|validat)\w*\s*\(/i,deprecated_marker:/@deprecated|#\[Deprecated/,registry_candidate:/\bclass\s+\w*(?:Registry|Catalog|Catalogue|Resolver)\b/,concrete_repository_reference:/\buse\s+Waaseyaa\\EntityStorage\\EntityRepository\s*;/};
for(const file of sources){const text=read(file),lines=text.split('\n');
 for(let i=0;i<lines.length;i++){
  const line=lines[i];
  for(const [kind,re]of Object.entries(patterns))if(re.test(line))signals.push({kind,path:file,line:i+1,primary_audit:owner(file),evidence:'lexical_candidate_not_proven_defect_or_reachability',excerpt:line.trim().slice(0,200)});
  if(/@api\b/.test(line))apiTagged.push({path:file,line:i+1,primary_audit:owner(file)});
  for(const [kind,re] of Object.entries({attribute:/^\s*#\[(?:[\\\w]+\\)?(?:AsAgentTool|AsCommand|AsMiddleware|PolicyAttribute|Route|AsEventListener|AsMessageHandler)\b/,provider_hook:/\bfunction\s+(?:routes|commands|nativeCommands|consoleCommands|middleware|eventSubscribers|register|boot)\s*\(/,route_registration:/\b(?:addRoute|registerRoute)\s*\(|\$router->(?:get|post|put|patch|delete|add)\s*\(/,command_declaration:/new\s+(?:[\\\w]+\\)?(?:CommandDefinition|HandlerCommand)\s*\(|#\[AsCommand|parent::__construct\(['"]/,worker_entry:/\bfunction\s+(?:runWorker|handleRequest|consume|work)\s*\(/}))if(re.test(line))entrypoints.push({kind,path:file,line:i+1,primary_audit:owner(file),evidence:'lexical_entrypoint_candidate',excerpt:line.trim().slice(0,180)});
 }
}
for(const p of packages){for(const provider of p.extra?.waaseyaa?.providers||[])entrypoints.push({kind:'manifest_provider',path:p.manifest,symbol:provider,primary_audit:p.primary_audit,evidence:'manifest_declaration'});for(const b of Array.isArray(p.bin)?p.bin:Object.values(p.bin))entrypoints.push({kind:'composer_binary',path:p.manifest,target:b,primary_audit:p.primary_audit,evidence:'manifest_declaration'});}
const executableFiles=files.filter(f=>f.startsWith('bin/')||f.startsWith('scripts/')||f.startsWith('.github/workflows/')||f.startsWith('skeleton/')||/^public\/.*\.php$/.test(f)||/^packages\/admin\/(app\/(pages|plugins|middleware)|server)\//.test(f));
const rootEntries=Object.entries(rootManifest.scripts||{}).map(([name,commands])=>({name,commands,primary_audit:'A6'}));
const gates=json('tools/preflight-gates.json');
const baselineFiles=files.filter(f=>/(baseline|allowlist|roster)/i.test(f)&&!f.startsWith('kitty-specs/')&&!f.includes('/tests/'));
const cyclePairs=edges.filter(e=>e.scope==='require'&&edges.some(r=>r.scope==='require'&&r.from===e.to&&r.to===e.from)&&e.from<e.to).map(e=>[e.from,e.to]);
const by=(xs,key)=>xs.reduce((r,x)=>(r[x[key]]=(r[x[key]]||0)+1,r),{});
const summary={head,tracked_files:files.length,package_directories:packages.length,composer_packages:packages.filter(p=>p.manifest?.endsWith('composer.json')).length,php_libraries:packages.filter(p=>p.kind==='library').length,metapackages:packages.filter(p=>p.kind==='metapackage').length,javascript_packages:packages.filter(p=>p.kind==='javascript').length,source_php_files:sources.length,package_primary_coverage:by(packages,'primary_audit'),file_primary_coverage:by(fileRoster,'primary_audit'),public_map_entries:publicMap.length,public_map_dispositions:by(publicMap,'disposition'),public_map_unresolved:publicMap.filter(p=>!p.source_candidates.length),api_tagged_sites:apiTagged.length,internal_dependency_edges:by(edges,'scope'),unresolved_internal_edges:edges.filter(e=>!e.resolves_locally),mutual_runtime_dependency_pairs:cyclePairs,entrypoint_candidates:by(entrypoints,'kind'),lexical_signals:by(signals,'kind'),preflight_gate_count:gates.gates.length,executable_support_files:executableFiles.length,baseline_allowlist_roster_files:baselineFiles.length,layer_map_gaps:packages.filter(p=>p.kind==='library'&&p.layer===null).map(p=>p.directory),layer_doc_drift:packages.filter(p=>p.kind==='library'&&p.layer!==p.documented_layer).map(p=>({package:p.directory,executable:p.layer,documented:p.documented_layer})),root_lock_sha256:sha('composer.lock'),root_manifest_sha256:sha('composer.json'),public_map_sha256:sha('docs/public-surface-map.php'),source_review:'static_census_only_no_behavioral_certification'};
fs.mkdirSync(out,{recursive:true});
const datasets={summary,packages,dependencies:edges,profiles,'file-roster':fileRoster,'public-surface':publicMap,symbols,'api-tagged-sites':apiTagged,entrypoints,'lexical-signals':signals,'support-surfaces':{executableFiles,rootScripts:rootEntries,gates,baselineFiles}};
for(const [name,data]of Object.entries(datasets))fs.writeFileSync(path.join(out,name+'.json'),JSON.stringify(data,null,2)+'\n');
console.log(JSON.stringify(summary,null,2));
