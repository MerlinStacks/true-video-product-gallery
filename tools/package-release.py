#!/usr/bin/env python3
"""Build and validate a deterministic, production-only WordPress plugin ZIP."""
import hashlib
import json
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SLUG = 'true-video-product-gallery'
MAIN = SLUG + '.php'


def require(condition, message):
    if not condition:
        raise SystemExit(message)


def build():
    header = (ROOT / MAIN).read_text()
    version = re.search(r'^ \* Version: (\d+\.\d+\.\d+)$', header, re.M).group(1)
    require("define( 'TVPG_VERSION', '" + version + "' );" in header, 'PHP version mismatch')
    require(f'Stable tag: {version}\n' in (ROOT / 'readme.txt').read_text(), 'Readme version mismatch')
    require(f'## [{version}]' in (ROOT / 'CHANGELOG.md').read_text(), 'Missing changelog')
    require((ROOT / 'release-notes' / (version + '.txt')).is_file(), 'Missing release notes')
    require(f"'TVPG_VERSION', '{version}'" in (ROOT / 'tests/bootstrap.php').read_text(), 'Test version mismatch')
    for name in ['package.json', 'package-lock.json', 'blocks/tvpg-product-video/block.json']:
        data = json.loads((ROOT / name).read_text())
        require(data['version'] == version, f'{name}: version mismatch')
        if 'packages' in data:
            require(data['packages']['']['version'] == version, 'Lock root version mismatch')

    library = ROOT / 'includes/plugin-update-checker'
    lock = json.loads((ROOT / 'tools/plugin-update-checker.lock.json').read_text())
    actual = {p.relative_to(library).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest()
              for p in library.rglob('*') if p.is_file()}
    require(actual == {**lock['files'], **lock.get('additional_files', {})}, 'Bundled updater differs from pinned files')

    files = [ROOT / name for name in [MAIN, 'uninstall.php', 'readme.txt', 'CHANGELOG.md', 'COMPATIBILITY.md']]
    for directory in ['includes', 'assets', 'templates', 'blocks', 'languages']:
        files.extend(p for p in (ROOT / directory).rglob('*') if p.is_file())
    contents = {}
    for path in sorted(files):
        rel = path.relative_to(ROOT)
        require(not path.is_symlink() and ROOT in path.resolve().parents, f'Unsafe file: {rel}')
        require(not any(part.startswith('.') or part in {'node_modules', 'tests', '__pycache__'} for part in rel.parts), f'Non-production file: {rel}')
        require(path.suffix.lower() not in {'.pem', '.key', '.zip', '.pyc', '.log'}, f'Unexpected file: {rel}')
        contents[f'{SLUG}/{rel.as_posix()}'] = path.read_bytes()

    required = [MAIN, 'includes/plugin-update-checker/plugin-update-checker.php',
                'includes/plugin-update-checker/license.txt',
                'includes/plugin-update-checker/vendor/Parsedown.php',
                'includes/plugin-update-checker/vendor/ParsedownModern.php',
                'includes/plugin-update-checker/vendor/PucReadmeParser.php',
                'includes/plugin-update-checker/vendor/Parsedown.LICENSE.txt',
                'assets/js/tvpg-frontend.min.js', 'assets/js/tvpg-block-editor.min.js',
                'assets/js/tvpg-archive.min.js', 'assets/css/tvpg-frontend.min.css',
                'assets/lib/swiper/swiper-slim.min.js']
    for name in required:
        require(f'{SLUG}/{name}' in contents, f'Missing runtime file: {name}')

    output = ROOT / 'dist' / f'{SLUG}-{version}.zip'
    output.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
        for name, data in sorted(contents.items()):
            info = zipfile.ZipInfo(name, date_time=(2020, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            info.create_system = 3
            archive.writestr(info, data)
    with zipfile.ZipFile(output) as archive:
        require(archive.testzip() is None, 'ZIP integrity failure')
        require(set(archive.namelist()) == set(contents), 'ZIP inventory mismatch')
        for name in archive.namelist():
            require(archive.read(name) == contents[name], f'ZIP content mismatch: {name}')
    print(f'Validated {len(contents)} files: {output}')
    print('SHA256: ' + hashlib.sha256(output.read_bytes()).hexdigest())


if __name__ == '__main__':
    build()
