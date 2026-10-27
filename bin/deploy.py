#!/usr/bin/env python3
"""Deploy a WordPress child-theme release and compiled config on the WP host.

This is a theme-only deployment: no content import, DB mutation or service restart.
"""

import argparse
import hashlib
import importlib.util
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import uuid

ROOT = Path(__file__).resolve().parent.parent
THEME_NAME = 'dtpstat-child'
CONFIG_NAME = 'dtpstat-config.php'

spec = importlib.util.spec_from_file_location('dtpstat_config_generator', ROOT / 'bin/generate-config.py')
generator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(generator)


def inspect_theme(theme):
    if not (theme / 'style.css').is_file() or not (theme / 'functions.php').is_file():
        raise ValueError('В источнике отсутствуют style.css или functions.php')
    digest = hashlib.sha256()
    php_files = []
    for file in sorted(theme.rglob('*')):
        if file.is_symlink():
            raise ValueError(f'Символические ссылки внутри темы запрещены: {file}')
        if file.is_dir():
            continue
        if not file.is_file():
            raise ValueError(f'Необычный тип файла темы: {file}')
        relative = file.relative_to(theme).as_posix()
        digest.update(relative.encode('utf-8') + b'\x00')
        with file.open('rb') as handle:
            for block in iter(lambda: handle.read(65536), b''):
                digest.update(block)
        if file.suffix == '.php':
            php_files.append(file)
    if not php_files:
        raise ValueError('В исходниках темы нет PHP-файлов')
    return digest.hexdigest()[:20], php_files


def verify_existing_theme_link(destination, allow_directory=False):
    """Do not replace a physical directory unless migration is explicitly requested."""
    if destination.is_symlink():
        target = destination.resolve()
        if (not target.is_dir() or
                not (target / 'style.css').is_file() or
                not (target / 'functions.php').is_file()):
            raise ValueError(f'Неизвестная или повреждённая ссылка темы: {destination} -> {target}')
        return os.readlink(destination)
    if destination.is_dir():
        if not (destination / 'style.css').is_file() or not (destination / 'functions.php').is_file():
            raise ValueError(f'Нельзя мигрировать неизвестный каталог темы: {destination}')
        if not allow_directory:
            raise ValueError(
                f'{destination} — обычный каталог, не symlink. '
                'Миграция требует --adopt-existing-directory и резервной копии.'
            )
        return 'DIRECTORY (migration requested)'
    if destination.exists():
        raise ValueError(f'Неожиданный тип назначения темы: {destination}')
    return None


def require_writable_directory(directory):
    if not directory.is_dir() or directory.is_symlink():
        raise ValueError(f'Нет обычного каталога: {directory}')
    if not os.access(directory, os.W_OK | os.X_OK):
        raise ValueError(f'Недостаточно прав на запись: {directory}')


def deploy(args):
    wp_root = args.wp_path.resolve(strict=True)
    if not (wp_root / 'wp-load.php').is_file():
        raise ValueError(f'В {wp_root} не найден wp-load.php')
    content = (args.content_dir.resolve(strict=True) if args.content_dir
               else wp_root / 'wp-content')
    themes = content / 'themes'
    release_base = content / 'dtpstat-releases'
    destination = themes / THEME_NAME
    config_file = content / CONFIG_NAME
    source_theme = ROOT / 'theme' / THEME_NAME

    if not content.is_dir() or not themes.is_dir():
        raise ValueError(f'Нет wp-content или themes: {content}')
    require_writable_directory(content)
    require_writable_directory(themes)
    if release_base.exists():
        require_writable_directory(release_base)
    existing_link = verify_existing_theme_link(destination, args.adopt_existing_directory)

    values = generator.read_env(args.env_file)
    generated = generator.render(values)
    generator.validate_destination(config_file)

    theme_hash, php_files = inspect_theme(source_theme)
    for file in php_files:
        result = subprocess.run(['php', '-l', str(file)],
                                capture_output=True, text=True, check=False)
        if result.returncode:
            raise RuntimeError(f'Ошибка PHP-синтаксиса в {file}: {result.stderr or result.stdout}')

    release = release_base / ('dtpstat-child-' + theme_hash)
    if release.exists():
        if release.is_symlink() or not release.is_dir():
            raise ValueError(f'Некорректная существующая версия темы: {release}')
        old_hash, _ = inspect_theme(release)
        if old_hash != theme_hash:
            raise ValueError(f'Существующий релиз изменён: {release}')
    elif not release_base.exists() and not os.access(content, os.W_OK | os.X_OK):
        raise ValueError(f'Нет прав на создание каталога версий: {release_base}')

    new_link = str(release)
    current = destination.resolve() if destination.is_symlink() else None
    config_current = config_file.read_text(encoding='utf-8') if config_file.exists() else None
    needs_config = config_current != generated
    needs_switch = current != release

    print(f'WordPress: {wp_root}')
    print(f'Источник темы: {source_theme}')
    print(f'Версия темы: {theme_hash}')
    print(f'Текущая ссылка: {existing_link or "(отсутствует)"}')
    print(f'Новая ссылка: {new_link}')
    print(f'Конфигурация: {config_file} ({"изменится" if needs_config else "без изменений"})')
    print(f'Тема: {"переключится" if needs_switch else "уже активна"}')

    if args.check:
        print('CHECK OK: никаких файлов не изменено')
        return

    # Build a complete release BEFORE changing the active theme link.
    if not release.exists():
        release_base.mkdir(mode=0o755, exist_ok=True)
        temporary = Path(tempfile.mkdtemp(prefix='.stage-', dir=release_base))
        try:
            shutil.copytree(source_theme, temporary / THEME_NAME)
            # The final release is immutable to this tool: it is never rsynced/updated in place.
            os.replace(temporary / THEME_NAME, release)
        finally:
            if temporary.exists():
                shutil.rmtree(temporary)

    # Compiler is the only writer of the PHP constants. It refuses unrelated files.
    if needs_config:
        generator.write_config(config_file, generated)

    previous_path = existing_link
    if needs_switch:
        fd, tempname = tempfile.mkstemp(prefix='.dtpstat-link-', dir=themes)
        os.close(fd)
        os.unlink(tempname)
        moved_directory = None
        try:
            os.symlink(new_link, tempname)
            if destination.is_dir() and not destination.is_symlink():
                # Explicit migration of a physical theme directory.
                # This cannot be completely atomic: save it intact and restore on error.
                moved_directory = release_base / ('legacy-' + uuid.uuid4().hex)
                os.rename(destination, moved_directory)
                previous_path = str(moved_directory)
            try:
                os.replace(tempname, destination)
            except OSError:
                if moved_directory and not destination.exists() and not destination.is_symlink():
                    os.rename(moved_directory, destination)
                raise
        finally:
            if os.path.lexists(tempname):
                os.unlink(tempname)

    print('DEPLOY OK: PHP-конфиг и тема установлены')
    if needs_switch:
        print(f'Предыдущая тема для ручного отката: {previous_path or "(не было)"}')
    print('Проверьте WordPress HTTP, карту, карточку ДТП и PHP/OPcache после выкладки.')


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--wp-path', required=True, type=Path,
                        help='абсолютный путь к установленному WordPress')
    parser.add_argument('--content-dir', type=Path,
                        help='WP_CONTENT_DIR, если он не <wp-path>/wp-content')
    parser.add_argument('--env-file', type=Path, default=ROOT / '.env',
                        help='проектный .env (по умолчанию <repo>/.env)')
    parser.add_argument('--check', action='store_true',
                        help='проверить все этапы, не изменяя сайт')
    parser.add_argument('--adopt-existing-directory', action='store_true',
                        help='осознанно мигрировать обычный каталог темы в резервную папку; без этого запрещено')
    args = parser.parse_args(argv)
    try:
        if not shutil.which('php'):
            raise ValueError('Для проверки исходников необходим php CLI')
        deploy(args)
    except (OSError, UnicodeError, ValueError, RuntimeError) as exc:
        parser.exit(1, f'ОШИБКА deploy: {exc}\n')


if __name__ == '__main__':
    main()
