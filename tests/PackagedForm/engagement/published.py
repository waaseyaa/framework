#!/usr/bin/env python3
"""Prepare and verify a genuine, exact-version Packagist consumer fixture."""
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import sys


_PREPARE_PATH = Path(__file__).with_name('prepare.py')
_SPEC = importlib.util.spec_from_file_location('_engagement_prepare', _PREPARE_PATH)
_PREPARE = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(_PREPARE)
digest_tree = _PREPARE.digest_tree


def _version(value):
    if not re.fullmatch(r'v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?', value):
        raise ValueError(f'Expected an exact semantic package version, got {value!r}')
    return value.removeprefix('v')


def prepare(source, target, fixture, version, absent=False):
    source, target, fixture = Path(source).resolve(), Path(target).resolve(), Path(fixture).resolve()
    cohort = _version(version)
    source_version = _version((source / 'VERSION').read_text().strip())
    if source_version != cohort:
        raise ValueError(f'Source VERSION {source_version} does not match requested cohort {cohort}')
    result = _PREPARE.prepare(source, fixture, target, absent)
    manifest_path = target / 'composer.json'
    manifest = json.loads(manifest_path.read_text())
    manifest.pop('repositories', None)
    # A historical cohort must not float transitive siblings to a later alpha.
    # Conflict constraints restrict candidates without adding optional packages.
    manifest['conflict'] = {
        json.loads(path.read_text())['name']: '!=' + cohort
        for path in sorted((source / 'packages').glob('*/composer.json'))
        if json.loads(path.read_text())['name'].startswith('waaseyaa/')
    }
    direct = manifest.get('require', {})
    waaseyaa = sorted(name for name in direct if name.startswith('waaseyaa/'))
    if not waaseyaa:
        raise ValueError('Fixture must directly require at least one waaseyaa package')
    for name in waaseyaa:
        direct[name] = cohort
    manifest_path.write_text(json.dumps(manifest, indent=4) + '\n')
    result.pop('version_overrides', None)
    result['cohort_label'] = cohort
    result['application_files'] = {
        relative: hashlib.sha256((target / relative).read_bytes()).hexdigest()
        for relative in result['application_files']
    }
    result['direct_waaseyaa_requirements'] = {name: cohort for name in waaseyaa}
    result['manifest_sha256'] = hashlib.sha256(manifest_path.read_bytes()).hexdigest()
    return result


def _source_export(source, package):
    """Return the already extracted split archive package tree."""
    root = Path(source).resolve()
    relative = Path('packages') / package.split('/', 1)[1]
    exported = root / relative
    if not exported.is_dir():
        raise AssertionError(('package absent from source archive', package))
    return exported


def verify(source, target, report_path, version):
    source, target = Path(source).resolve(), Path(target).resolve()
    cohort = _version(version)
    application = json.loads(Path(report_path).read_text())
    if application.get('cohort_label') != cohort:
        raise AssertionError(('report cohort differs from requested version', application.get('cohort_label'), cohort))
    expected_application = application['application_files']
    for relative, expected in expected_application.items():
        path = target / relative
        assert path.is_file() and not path.is_symlink(), ('application file absent or linked', relative)
        assert hashlib.sha256(path.read_bytes()).hexdigest() == expected, ('application candidate bytes changed', relative)
    for directory in ['src', 'migrations']:
        actual = {p.relative_to(target).as_posix() for p in (target / directory).rglob('*') if p.is_file()} if (target / directory).is_dir() else set()
        expected = {p for p in expected_application if p.startswith(directory + '/')}
        assert actual == expected, ('unexpected or missing application files', directory, sorted(actual ^ expected))
    manifest_path = target / 'composer.json'
    manifest_bytes = manifest_path.read_bytes()
    assert hashlib.sha256(manifest_bytes).hexdigest() == application['manifest_sha256'], 'Overlay manifest changed'
    manifest = json.loads(manifest_bytes)
    assert 'repositories' not in manifest, 'Consumer manifest must have no repositories'
    expected_cohort_constraints = {
        json.loads(path.read_text())['name']: '!=' + cohort
        for path in sorted((source / 'packages').glob('*/composer.json'))
        if json.loads(path.read_text())['name'].startswith('waaseyaa/')
    }
    assert manifest.get('conflict') == expected_cohort_constraints, 'Complete exact-cohort constraints are required'
    direct = {name: constraint for name, constraint in manifest.get('require', {}).items() if name.startswith('waaseyaa/')}
    assert direct and all(constraint == cohort for constraint in direct.values()), ('direct waaseyaa requirements are not exact cohort pins', direct)

    lock_path = target / 'composer.lock'
    lock = json.loads(lock_path.read_text())
    installed_data = json.loads((target / 'vendor/composer/installed.json').read_text())
    assert isinstance(installed_data, dict) and isinstance(installed_data.get('dev'), bool), 'Composer 2 installed metadata with dev state is required'
    installed = installed_data.get('packages')
    assert isinstance(installed, list), 'Composer installed package list is missing'
    expected_packages = lock.get('packages', []) + (lock.get('packages-dev', []) if installed_data['dev'] else [])
    assert {p['name'] for p in installed} == {p['name'] for p in expected_packages}, 'Installed package set differs from solved lock closure/dev state'
    locked = {item['name']: item for key in ['packages', 'packages-dev'] for item in lock.get(key, [])}
    result_packages = []
    for package in installed:
        name = package['name']
        if not name.startswith('waaseyaa/'):
            continue
        assert package.get('version', '').removeprefix('v') == cohort, ('wrong installed cohort version', name, package.get('version'))
        locked_package = locked.get(name)
        assert locked_package is not None, ('installed package absent from lock', name)
        source_meta, dist_meta = package.get('source') or {}, package.get('dist') or {}
        lock_source, lock_dist = locked_package.get('source') or {}, locked_package.get('dist') or {}
        repository = name.split('/', 1)[1]
        expected_source = f'https://github.com/waaseyaa/{repository}.git'
        assert source_meta.get('type') == 'git' and source_meta.get('url') == expected_source, ('unexpected GitHub source repository', name, source_meta)
        expected_dist = rf'https://api\.github\.com/repos/waaseyaa/{re.escape(repository)}/zipball/[0-9a-f]{{40}}'
        assert dist_meta.get('type') == 'zip' and re.fullmatch(expected_dist, dist_meta.get('url', '')), ('unexpected GitHub zip distribution', name, dist_meta)
        source_ref, dist_ref = source_meta.get('reference'), dist_meta.get('reference')
        assert isinstance(source_ref, str) and re.fullmatch(r'[0-9a-f]{40}', source_ref) and source_ref == dist_ref, ('source and dist references differ or are not full commit ids', name, source_ref, dist_ref)
        assert source_meta == lock_source and dist_meta == lock_dist, ('lock/installed origin drift', name)
        source_package = _source_export(source, name)
        source_manifest = json.loads((source_package / 'composer.json').read_text())
        for field in ['name', 'type', 'require', 'autoload', 'extra', 'provide', 'replace', 'conflict']:
            if field in source_manifest:
                assert package.get(field) == source_manifest[field], ('installed package manifest semantics differ from exported source', name, field)
        item = {'name': name, 'version': package['version'], 'source': source_meta, 'dist': dist_meta, 'reference': source_ref}
        if package.get('type') == 'metapackage':
            assert package.get('install-path') is None and not (target / 'vendor' / name).exists(), ('metapackage unexpectedly has installed files', name)
        else:
            installed_path = target / 'vendor' / name
            assert installed_path.is_dir() and not installed_path.is_symlink(), ('package missing or symlinked', name)
            assert installed_path.resolve().is_relative_to((target / 'vendor').resolve()), ('installed package escaped vendor', name)
            install_path = package.get('install-path')
            assert isinstance(install_path, str), ('installed package metadata has no install path', name)
            resolved_install_path = (target / 'vendor' / 'composer' / install_path).resolve()
            assert resolved_install_path == installed_path.resolve(), ('Composer install-path does not identify vendor package', name, install_path)
            assert package.get('installation-source') == 'dist', ('package was not installed from dist bytes', name, package.get('installation-source'))
            export_sha, export_rows = digest_tree(source_package)
            installed_sha, installed_rows = digest_tree(installed_path)
            assert not any(value.startswith('link:') for _, value in installed_rows), ('symlink in installed package', name)
            assert export_rows == installed_rows, ('installed package bytes differ from SOURCE archive export', name)
            assert (installed_path / 'composer.json').read_bytes() == (source_package / 'composer.json').read_bytes(), ('installed composer.json differs from SOURCE archive', name)
            item.update({'export_sha256': export_sha, 'installed_sha256': installed_sha, 'installed_files': len(installed_rows)})
        result_packages.append(item)
    assert result_packages, 'No waaseyaa packages installed'
    return {
        'cohort_label': cohort,
        'application_fixture_sha256': application['fixture_sha256'],
        'application_files_verified': len(expected_application),
        'manifest_sha256': hashlib.sha256(manifest_bytes).hexdigest(),
        'lock_sha256': hashlib.sha256(lock_path.read_bytes()).hexdigest(),
        'installed_dev': installed_data['dev'],
        'packages': result_packages,
        'third_party': [{'name': p['name'], 'version': p.get('version'), 'source': p.get('source'), 'dist': p.get('dist')} for p in installed if not p['name'].startswith('waaseyaa/')],
    }


if __name__ == '__main__':
    mode, source, target, *args = sys.argv[1:]
    if mode == 'prepare':
        if len(args) not in (2, 3):
            raise SystemExit('usage: published.py prepare SOURCE TARGET FIXTURE VERSION [absent]')
        value = prepare(Path(source), Path(target), Path(args[0]), args[1], len(args) == 3 and args[2] == 'absent')
    elif mode == 'verify':
        if len(args) != 2:
            raise SystemExit('usage: published.py verify SOURCE TARGET REPORT VERSION')
        value = verify(Path(source), Path(target), Path(args[0]), args[1])
    else:
        raise SystemExit('usage: published.py {prepare|verify} ...')
    print(json.dumps(value, indent=2))
