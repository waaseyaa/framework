#!/usr/bin/env node

import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { copyFileSync, existsSync, mkdtempSync, mkdirSync, readFileSync, readdirSync, rmSync, unlinkSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { basename, dirname, join, resolve } from 'node:path'
import { createHash } from 'node:crypto'

const repoRoot = resolve(import.meta.dirname, '../..')
const temporaryRoot = mkdtempSync(join(tmpdir(), 'waaseyaa_release_evidence_'))

try {
  const workflowDirectory = join(repoRoot, '.github/workflows')
  let externalUses = 0
  for (const file of readdirSync(workflowDirectory).filter((name) => name.endsWith('.yml'))) {
    const workflow = readFileSync(join(workflowDirectory, file), 'utf8')
    for (const match of workflow.matchAll(/^\s*-?\s*uses:\s*([^\s#]+)(?:\s+#\s*(.+))?$/gm)) {
      if (match[1].startsWith('./')) continue
      externalUses++
      assert.match(match[1], /^[^@\s]+@[0-9a-f]{40}$/, `${file}: mutable action ${match[1]}`)
      assert.notEqual((match[2] ?? '').trim(), '', `${file}: action pin lacks a version comment`)
    }
  }
  assert.ok(externalUses > 0)

  const sourceSha = 'a'.repeat(40)
  const splitDirectory = join(temporaryRoot, 'splits')
  mkdirSync(splitDirectory)
  const manifestPaths = readdirSync(join(repoRoot, 'packages'))
    .map((name) => `packages/${name}/composer.json`)
    .filter((name) => existsSync(join(repoRoot, name)))
    .sort()
  for (const relativeManifest of manifestPaths) {
    const manifest = JSON.parse(readFileSync(join(repoRoot, relativeManifest), 'utf8'))
    const prefix = dirname(relativeManifest)
    const remote = basename(prefix)
    writeFileSync(join(splitDirectory, `${remote}.json`), JSON.stringify({
      source_repository: 'waaseyaa/framework',
      source_sha: sourceSha,
      package: manifest.name,
      prefix,
      split_repository: `waaseyaa/${remote}`,
      split_sha: createHash('sha1').update(manifest.name).digest('hex'),
      tag: 'v0.1.0-alpha.test',
      run_id: '12345',
    }))
  }

  const generate = (outputDirectory) => spawnSync(join(repoRoot, 'bin/generate-release-evidence'), [
    `--repository-root=${repoRoot}`,
    `--split-provenance-dir=${splitDirectory}`,
    `--output-dir=${outputDirectory}`,
    '--source-repository=waaseyaa/framework',
    `--source-sha=${sourceSha}`,
    '--tag=v0.1.0-alpha.test',
    '--builder-id=https://github.com/waaseyaa/framework/actions/workflows/split.yml',
    '--workflow-run-id=12345',
    '--composer-version=2.9.0',
    '--node-version=24.0.0',
  ], { encoding: 'utf8' })

  const first = join(temporaryRoot, 'first')
  const second = join(temporaryRoot, 'second')
  let result = generate(first)
  assert.equal(result.status, 0, result.stderr || result.stdout)
  result = generate(second)
  assert.equal(result.status, 0, result.stderr || result.stdout)

  for (const file of ['waaseyaa-framework.cdx.json', 'waaseyaa-framework-provenance.json', 'SHA256SUMS']) {
    assert.deepEqual(readFileSync(join(first, file)), readFileSync(join(second, file)), `${file} is not deterministic`)
  }

  if (process.env.RELEASE_EVIDENCE_DRY_RUN_OUTPUT) {
    const retainedOutput = resolve(repoRoot, process.env.RELEASE_EVIDENCE_DRY_RUN_OUTPUT)
    mkdirSync(retainedOutput, { recursive: true })
    for (const file of ['waaseyaa-framework.cdx.json', 'waaseyaa-framework-provenance.json', 'SHA256SUMS']) {
      copyFileSync(join(first, file), join(retainedOutput, file))
    }
  }

  const sbom = JSON.parse(readFileSync(join(first, 'waaseyaa-framework.cdx.json'), 'utf8'))
  for (const component of sbom.components) {
    for (const property of component.properties ?? []) {
      assert.ok(
        !Object.hasOwn(property, 'value') || typeof property.value === 'string',
        `${component.purl}: CycloneDX property ${property.name} has a non-string value`,
      )
    }
  }
  const purls = new Set(sbom.components.map((component) => component.purl))
  const composer = JSON.parse(readFileSync(join(repoRoot, 'composer.lock'), 'utf8'))
  for (const dependency of [...composer.packages, ...composer['packages-dev']]) {
    assert.ok(purls.has(`pkg:composer/${dependency.name}@${encodeURIComponent(dependency.version)}`), `missing ${dependency.name}`)
  }
  const npm = JSON.parse(readFileSync(join(repoRoot, 'packages/admin/package-lock.json'), 'utf8'))
  for (const [path, dependency] of Object.entries(npm.packages)) {
    if (path === '' || !dependency.version) continue
    const name = dependency.name ?? path.replace(/^.*node_modules\//, '')
    assert.ok(purls.has(`pkg:npm/${encodeURIComponent(name).replaceAll('%2F', '/')}@${encodeURIComponent(dependency.version)}`), `missing ${name}`)
  }

  const provenance = JSON.parse(readFileSync(join(first, 'waaseyaa-framework-provenance.json'), 'utf8'))
  assert.equal(provenance.source.sha, sourceSha)
  assert.equal(provenance.locks.composer.sha256, createHash('sha256').update(readFileSync(join(repoRoot, 'composer.lock'))).digest('hex'))
  assert.equal(provenance.locks.npm.sha256, createHash('sha256').update(readFileSync(join(repoRoot, 'packages/admin/package-lock.json'))).digest('hex'))
  assert.equal(provenance.packages.length, manifestPaths.length)

  unlinkSync(join(splitDirectory, readdirSync(splitDirectory)[0]))
  result = generate(join(temporaryRoot, 'incomplete'))
  assert.equal(result.status, 1, 'incomplete split provenance must fail')

  const splitWorkflow = readFileSync(join(workflowDirectory, 'split.yml'), 'utf8')
  assert.match(splitWorkflow, /assemble-release-evidence:/)
  assert.match(splitWorkflow, /publish-github-release:.*?needs:\s*\[[^\]]*assemble-release-evidence/s)
  assert.match(splitWorkflow, /files: release-evidence\/\*/)

  console.log('release evidence regression: PASS')
} finally {
  rmSync(temporaryRoot, { recursive: true, force: true })
}
