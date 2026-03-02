import { strict as assert } from 'node:assert';
import { test, describe } from 'node:test';
import { listSpecs, getSpec, searchSpecs } from './server.js';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const fixturesDir = path.join(__dirname, 'test-fixtures');

describe('waaseyaa_list_specs', () => {
  test('returns all spec files from directory', async () => {
    const result = await listSpecs(fixturesDir);
    assert.ok(Array.isArray(result), 'Result should be an array');
    assert.equal(result.length, 3, 'Should find 3 test fixtures');

    const names = result.map((s) => s.name).sort();
    assert.deepEqual(names, ['Alpha Feature', 'Beta Service', 'Gamma API']);
  });

  test('each spec has name, description, and file fields', async () => {
    const result = await listSpecs(fixturesDir);
    for (const spec of result) {
      assert.ok(spec.name, `Spec should have a name: ${JSON.stringify(spec)}`);
      assert.ok(spec.description, `Spec should have a description: ${JSON.stringify(spec)}`);
      assert.ok(spec.file, `Spec should have a file: ${JSON.stringify(spec)}`);
    }
  });

  test('description comes from Overview section or first paragraph', async () => {
    const result = await listSpecs(fixturesDir);
    const alpha = result.find((s) => s.name === 'Alpha Feature');
    assert.ok(alpha);
    assert.ok(
      alpha.description.includes('caching and validation'),
      'Alpha description should mention caching and validation'
    );

    const gamma = result.find((s) => s.name === 'Gamma API');
    assert.ok(gamma);
    assert.ok(
      gamma.description.includes('REST API gateway'),
      'Gamma description should come from first paragraph when no Overview section'
    );
  });

  test('returns empty array for empty directory', async () => {
    const emptyDir = path.join(__dirname, 'test-fixtures', 'nonexistent-subdir');
    const result = await listSpecs(emptyDir);
    assert.ok(Array.isArray(result));
    assert.equal(result.length, 0);
  });
});

describe('waaseyaa_get_spec', () => {
  test('returns full content of a spec by name', async () => {
    const content = await getSpec('alpha-feature', fixturesDir);
    assert.ok(content.includes('# Alpha Feature'));
    assert.ok(content.includes('## Architecture'));
    assert.ok(content.includes('AlphaCache.php'));
  });

  test('returns full content of another spec', async () => {
    const content = await getSpec('beta-service', fixturesDir);
    assert.ok(content.includes('# Beta Service'));
    assert.ok(content.includes('## Queue System'));
  });

  test('throws for nonexistent spec', async () => {
    await assert.rejects(
      () => getSpec('nonexistent-spec', fixturesDir),
      { message: /not found/i }
    );
  });
});

describe('waaseyaa_search_specs', () => {
  test('finds matching sections by keyword', async () => {
    const results = await searchSpecs('authentication', fixturesDir);
    assert.ok(Array.isArray(results), 'Results should be an array');
    assert.ok(results.length > 0, 'Should find at least one match');

    const files = results.map((r) => r.file);
    assert.ok(
      files.includes('beta-service.md'),
      'Should find authentication in beta-service'
    );
  });

  test('search is case-insensitive', async () => {
    const upper = await searchSpecs('AUTHENTICATION', fixturesDir);
    const lower = await searchSpecs('authentication', fixturesDir);
    assert.equal(upper.length, lower.length, 'Case should not affect results');
  });

  test('returns matching section with heading', async () => {
    const results = await searchSpecs('cache', fixturesDir);
    const alphaMatch = results.find((r) => r.file === 'alpha-feature.md');
    assert.ok(alphaMatch, 'Should find cache reference in alpha-feature');
    assert.ok(alphaMatch.section, 'Match should include section heading');
    assert.ok(alphaMatch.content, 'Match should include content');
  });

  test('returns empty array for no matches', async () => {
    const results = await searchSpecs('zyxwvutsrqp_no_match', fixturesDir);
    assert.ok(Array.isArray(results));
    assert.equal(results.length, 0);
  });

  test('matches across multiple specs', async () => {
    const results = await searchSpecs('entity', fixturesDir);
    const files = [...new Set(results.map((r) => r.file))];
    assert.ok(files.length >= 1, 'Should find entity references');
  });
});

console.log('All tests defined. Running via node --test...');
