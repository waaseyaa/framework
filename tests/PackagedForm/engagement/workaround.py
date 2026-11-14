#!/usr/bin/env python3
"""Explicit two-package metadata workaround, never standard Packagist proof."""
import hashlib
import json
from pathlib import Path
import re
import sys

import published

ALPHA309_REFERENCES = {
    'waaseyaa/publishing': '5da63931f33fe83100aca6b698c252f287b211d5',
    'waaseyaa/wayfinding': '2db31c587cc65012579f88c122507923d75b580c',
}


def repositories(snapshot, source, version):
    data = json.loads(Path(snapshot).read_text())
    assert data['cohort'] == '0.1.0-alpha.309', 'Workaround is bounded to alpha.309'
    packages = data['packages']
    assert len(packages) == 2, 'Exactly two metadata overrides are required'
    assert {p['name'] for p in packages} == {'waaseyaa/publishing', 'waaseyaa/wayfinding'}, 'Unexpected metadata override'
    # Historical alpha.308 must continue to resolve from standard Packagist.
    if published._version(version) == '0.1.0-alpha.308':
        return None
    assert published._version(version) == data['cohort'], 'Unexpected workaround cohort'
    result = []
    for package in packages:
        assert set(package) <= {'name', 'version', 'require', 'require-dev', 'autoload', 'autoload-dev', 'type', 'license', 'description', 'source', 'dist', 'extra', 'conflict', 'provide', 'replace', 'suggest'}, 'Unexpected package metadata field'
        name = package['name']
        assert published._version(package['version']) == data['cohort'], 'Wrong override version'
        repository = name.split('/')[1]
        origin, dist = package['source'], package['dist']
        ref = origin['reference']
        assert re.fullmatch(r'[0-9a-f]{40}', ref), 'Expected immutable commit'
        assert ref == ALPHA309_REFERENCES[name], 'Reference differs from alpha.309 split provenance'
        assert origin == {'type': 'git', 'url': f'https://github.com/waaseyaa/{repository}.git', 'reference': ref}, 'Unexpected source'
        assert dist['type'] == 'zip' and dist['reference'] == ref, 'Unexpected dist reference'
        assert dist['url'] == f'https://api.github.com/repos/waaseyaa/{repository}/zipball/{ref}', 'Unexpected distribution URL'
        manifest = json.loads((Path(source) / 'packages' / repository / 'composer.json').read_text())
        for field in ['name', 'type', 'require', 'require-dev', 'autoload', 'extra', 'provide', 'replace', 'conflict', 'suggest']:
            assert package.get(field) == manifest.get(field), ('Override differs from tagged package manifest', name, field)
        # Packagist omits dependency test autoload mappings from public metadata.
        if 'autoload-dev' in package:
            assert package['autoload-dev'] == manifest.get('autoload-dev'), 'Unexpected development autoload'
        result.append({'type': 'package', 'package': package})
    return result


if __name__ == '__main__':
    snapshot, mode, source, target, *args = sys.argv[1:]
    version = args[1]
    overrides = repositories(snapshot, source, version)
    if mode == 'prepare':
        value = published.prepare(source, target, args[0], version, len(args) == 3 and args[2] == 'absent')
        if overrides is not None:
            path = Path(target) / 'composer.json'
            manifest = json.loads(path.read_text())
            manifest['repositories'] = overrides
            path.write_text(json.dumps(manifest, indent=4) + '\n')
            value['manifest_sha256'] = hashlib.sha256(path.read_bytes()).hexdigest()
            value['application_files']['composer.json'] = value['manifest_sha256']
        value['qualification_mode'] = 'metadata-workaround'
        value['metadata_snapshot_sha256'] = hashlib.sha256(Path(snapshot).read_bytes()).hexdigest()
    elif mode == 'verify':
        report = json.loads(Path(args[0]).read_text())
        assert report['qualification_mode'] == 'metadata-workaround'
        assert report['metadata_snapshot_sha256'] == hashlib.sha256(Path(snapshot).read_bytes()).hexdigest(), 'Metadata snapshot changed'
        value = published.verify(source, target, args[0], version, expected_repositories=overrides)
        value['qualification_mode'] = 'metadata-workaround'
        value['metadata_snapshot_sha256'] = report['metadata_snapshot_sha256']
    else:
        raise SystemExit('Expected prepare or verify')
    print(json.dumps(value, indent=2))
