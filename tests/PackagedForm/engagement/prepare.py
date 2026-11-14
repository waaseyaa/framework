#!/usr/bin/env python3
"""Prepare and verify copied candidate packages; never supplies runtime autoload rules."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import sys


def digest_tree(root):
    rows = []
    for path in sorted(root.rglob('*')):
        if path.is_symlink():
            value = 'link:' + os.readlink(path)
        elif path.is_file():
            value = hashlib.sha256(path.read_bytes()).hexdigest()
        else:
            continue
        rows.append([path.relative_to(root).as_posix(), value])
    return hashlib.sha256(json.dumps(rows, separators=(',', ':')).encode()).hexdigest(), rows


def prepare(source, fixture, target, absent=False):
    shutil.copytree(fixture, target, dirs_exist_ok=True)
    manifest = json.loads((target / 'composer.json').read_text())
    version = (source / 'VERSION').read_text().strip()
    versions = {}
    for path in sorted((source / 'packages').glob('*/composer.json')):
        name = json.loads(path.read_text())['name']
        if name.startswith('waaseyaa/'):
            versions[name] = version
    source_relative = os.path.relpath(source, target)
    manifest['repositories'] = [{'type': 'path', 'url': source_relative + '/packages/*',
        'only': ['waaseyaa/*'], 'options': {'symlink': False, 'versions': versions}}]
    manifest['config']['sort-packages'] = True
    manifest['config']['allow-plugins'] = {}
    if absent:
        del manifest['require']['waaseyaa/engagement']
        manifest.pop('autoload', None)
        manifest['extra']['waaseyaa'] = {}
        shutil.rmtree(target / 'src')
        shutil.rmtree(target / 'migrations', ignore_errors=True)
        (target / 'config' / 'waaseyaa.php').write_text("<?php\nreturn ['http_security' => ['rate_limit' => ['enabled' => false]]];\n")
    (target / 'composer.json').write_text(json.dumps(manifest, indent=4) + '\n')
    for path in ['storage/framework', 'storage/files', 'config/sync']:
        (target / path).mkdir(parents=True, exist_ok=True)
    paths = [p.relative_to(fixture) for p in fixture.rglob('*') if p.is_file()]
    if fixture.name == 'consumer':
        for name in ['bootstrap.php', 'journey.php', 'performance.php', 'concurrency.php', 'quota.php', 'upgrade.php', 'absent.php']:
            origin = fixture.parent / name
            shutil.copyfile(origin, target / name)
            paths.append(Path(name))
    application_files = {p.as_posix(): hashlib.sha256((target / p).read_bytes()).hexdigest()
        for p in paths if (target / p).is_file()}
    return {'cohort_label': version, 'version_overrides': versions, 'application_files': application_files,
        'fixture_sha256': digest_tree(fixture)[0], 'manifest_sha256': hashlib.sha256((target / 'composer.json').read_bytes()).hexdigest()}


def verify(source, target, application_report):
    application = json.loads(application_report.read_text())
    expected_application = application['application_files']
    for relative, expected in expected_application.items():
        path = target / relative
        assert path.is_file() and not path.is_symlink(), ('application file absent or linked', relative)
        assert hashlib.sha256(path.read_bytes()).hexdigest() == expected, ('application candidate bytes changed', relative)
    for directory in ['src', 'migrations']:
        if (target / directory).is_dir():
            assert {p.relative_to(target).as_posix() for p in (target / directory).rglob('*') if p.is_file()} == {
                p for p in expected_application if p.startswith(directory + '/')}, ('unexpected application code', directory)
    assert hashlib.sha256((target / 'composer.json').read_bytes()).hexdigest() == application['manifest_sha256'], 'Overlay manifest changed'

    lock = json.loads((target / 'composer.lock').read_text())
    installed_data = json.loads((target / 'vendor/composer/installed.json').read_text())
    assert isinstance(installed_data, dict), 'Composer 2 installed metadata is required'
    installed = installed_data['packages']
    expected_packages = lock['packages'] + (lock.get('packages-dev', []) if installed_data['dev'] else [])
    assert {p['name'] for p in installed} == {p['name'] for p in expected_packages}, 'Installed package set differs from solved lock closure'
    locked = {item['name']: item for key in ['packages', 'packages-dev'] for item in lock.get(key, [])}
    report = []
    for package in installed:
        name = package['name']
        if not name.startswith('waaseyaa/'):
            continue
        original = source / 'packages' / name.split('/')[1]
        assert package['version'].removeprefix('v') == (source / 'VERSION').read_text().strip(), ('wrong candidate cohort label', name)
        assert original.is_dir(), ('unexplained candidate/published package mixing', name)
        assert package.get('dist', {}).get('type') == 'path', ('not candidate path origin', name)
        expected_url = os.path.relpath(original, target).replace(os.sep, '/')
        assert package['dist']['url'].replace(os.sep, '/') == expected_url, ('wrong export origin', name)
        assert locked[name]['dist'] == package['dist'], ('lock/installed origin drift', name)
        item = {'name': name, 'version': package['version'], 'dist': package['dist']}
        if package.get('type') == 'metapackage':
            assert package.get('install-path') is None, ('metapackage has files', name)
            assert not (target / 'vendor' / name).exists(), ('metapackage unexpectedly extracted', name)
        else:
            path = target / 'vendor' / name
            assert not path.is_symlink(), ('symlinked candidate package', name)
            assert path.resolve().is_relative_to((target / 'vendor').resolve()), ('install escaped local vendor', name)
            expected_digest, expected_rows = digest_tree(original)
            installed_digest, installed_rows = digest_tree(path)
            assert expected_rows == installed_rows, ('installed bytes differ from export', name)
            item['export_sha256'] = expected_digest
            item['installed_sha256'] = installed_digest
            item['installed_files'] = len(installed_rows)
        report.append(item)
    assert report, 'No candidate packages installed'
    return {'application_fixture_sha256': application['fixture_sha256'], 'application_files_verified': len(expected_application), 'lock_sha256': hashlib.sha256((target / 'composer.lock').read_bytes()).hexdigest(),
        'packages': report, 'third_party': [{k: p.get(k) for k in ['name', 'version', 'source', 'dist']}
            for p in installed if not p['name'].startswith('waaseyaa/')]}


if __name__ == '__main__':
    mode, source, target, *args = sys.argv[1:]
    if mode == 'prepare':
        value = prepare(Path(source).resolve(), Path(args[0]).resolve(), Path(target).resolve(), 'absent' in args)
    elif mode == 'verify':
        value = verify(Path(source).resolve(), Path(target).resolve(), Path(args[0]))
    else:
        raise ValueError('Unknown preparation operation')
    print(json.dumps(value, indent=2))
