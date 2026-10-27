#!/usr/bin/env python3
"""Local WordPress package installer. No shell evaluation, no credentials."""
import argparse
import datetime as dt
import fcntl
import hashlib
import json
import os
from pathlib import Path
import selectors
import shlex
import shutil
import signal
import subprocess
import sys
import tempfile
import time
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
LOG = None
CHILD = None
STEP = 'запуск'
MUTATIONS_STARTED = False
MIN_PYTHON = (3, 8)


def check_python(version):
    if version[:2] < MIN_PYTHON:
        raise RuntimeError('Требуется Python >= 3.8; обнаружен ' + '.'.join(map(str, version[:3])))


def failure_message(kind, reason=''):
    detail = f': {reason}' if reason else ''
    state = ('Сайт мог измениться частично; повторный импорт не запускайте вслепую.'
             if MUTATIONS_STARTED else 'Установщик не начинал изменять сайт.')
    return f'{kind}: {STEP}{detail}. {state}'


def log(message):
    text = f'[{dt.datetime.now().astimezone().isoformat(timespec="seconds")}] {message}'
    print(text, flush=True)
    if LOG:
        LOG.write(text + '\n')
        LOG.flush()


def stop_child():
    if CHILD:
        try:
            os.killpg(CHILD.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        try:
            CHILD.wait(timeout=5)
        except subprocess.TimeoutExpired:
            try:
                os.killpg(CHILD.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            CHILD.wait()


def interrupted(signum, frame):
    raise KeyboardInterrupt


def run(argv, limit, capture=False):
    global CHILD
    log('$ ' + shlex.join(map(str, argv)))
    started = last = time.monotonic()
    data = bytearray()
    CHILD = subprocess.Popen(list(map(str, argv)), cwd=ROOT, stdin=subprocess.DEVNULL,
                             stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                             start_new_session=True)
    try:
        with selectors.DefaultSelector() as selector:
            selector.register(CHILD.stdout, selectors.EVENT_READ)
            while selector.get_map():
                now = time.monotonic()
                if now - started >= limit:
                    raise RuntimeError(f'Тайм-аут {limit} с; команда остановлена')
                if now - last >= 15:
                    log(f'… выполняется: {STEP}, {int(now - started)} с')
                    last = now
                for key, _ in selector.select(timeout=0.5):
                    chunk = os.read(key.fileobj.fileno(), 65536)
                    if not chunk:
                        selector.unregister(key.fileobj)
                        continue
                    if capture:
                        data.extend(chunk)
                    else:
                        text = chunk.decode('utf-8', errors='replace')
                        print(text, end='', flush=True)
                        if LOG:
                            LOG.write(text)
                            LOG.flush()
            remaining = max(0.1, limit - (time.monotonic() - started))
            rc = CHILD.wait(timeout=remaining)
        output = data.decode('utf-8', errors='replace')
        if rc:
            if output:
                log(output.rstrip())
            raise RuntimeError(f'Команда завершилась с кодом {rc}')
        log(f'OK ({time.monotonic() - started:.1f} с)')
        return output
    finally:
        stop_child()
        if CHILD.stdout:
            CHILD.stdout.close()
        CHILD = None


def json_output(text):
    marker = 'DTPSTAT_JSON:'
    lines = text.splitlines()
    matches = [line[len(marker):] for line in lines if line.startswith(marker)]
    for line in lines:
        if not line.startswith(marker):
            log(line)
    if len(matches) != 1:
        raise RuntimeError('Ожидался один JSON-ответ проверки WP; см. вывод выше')
    return json.loads(matches[0])


def within(path, parent):
    try:
        path.resolve().relative_to(parent.resolve())
        return True
    except ValueError:
        return False


def require_file(path):
    if not path.is_file() or not os.access(path, os.R_OK):
        raise RuntimeError(f'Нет читаемого файла: {path}')


def source_path(base, relative):
    if not isinstance(relative, str) or not relative or Path(relative).is_absolute():
        raise RuntimeError(f'Некорректный относительный путь: {relative!r}')
    path = base / relative
    if not within(path, base):
        raise RuntimeError(f'Путь выходит за uploads: {relative}')
    require_file(path)
    return path


def stage(name):
    global STEP
    STEP = name
    log('\n>> ' + name)


def inspect_content(content, theme, destination):
    needed = 0
    link = None
    for path in content.rglob('*'):
        if path.is_symlink():
            if path != destination or path.resolve() != theme.resolve():
                raise RuntimeError(f'Неизвестная символическая ссылка в wp-content: {path}')
            link = {'path': str(path), 'target': os.readlink(path)}
            log(f'Тема подключена ссылкой: {path} -> {path.resolve()}; ссылка сохраняется')
            continue
        required = os.R_OK | os.W_OK | (os.X_OK if path.is_dir() else 0)
        if not os.access(path, required):
            raise RuntimeError(f'Недостаточно прав чтения/записи для установки и копирования: {path}')
        if path.is_file():
            needed += path.stat().st_size
    if link:
        for path in theme.rglob('*'):
            if path.is_symlink():
                raise RuntimeError(f'Внутренняя ссылка в исходниках темы: {path}')
            if not os.access(path, os.R_OK | (os.X_OK if path.is_dir() else 0)):
                raise RuntimeError(f'Не удаётся прочитать тему для резервной копии: {path}')
            if path.is_file():
                needed += path.stat().st_size
    return needed, link


def sync_theme(theme, destination, limit):
    if theme.resolve() == destination.resolve():
        log('Тема уже подключена из проекта; копирование пропущено, символическая ссылка сохранена')
        return
    run(['rsync', '-rlt', '--chmod=D755,F644', '--itemize-changes', str(theme) + '/',
         str(destination) + '/'], limit)


def main():
    global LOG
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--wp-path', required=True, type=Path)
    p.add_argument('--wp-bin', default='wp', help='исполняемый файл wp, без аргументов')
    p.add_argument('--wp-cli-phar', type=Path, help='вместо wp использовать php /path/wp-cli.phar')
    p.add_argument('--url', help='адрес сайта; по умолчанию текущий home')
    p.add_argument('--env-file', type=Path, default=ROOT / '.env',
                   help='проектный .env для генерации PHP-констант, по умолчанию <repo>/.env')
    p.add_argument('--allow-root', action='store_true')
    p.add_argument('--check', action='store_true', help='только предварительные проверки')
    p.add_argument('--remove-stock-content', action='store_true', help='удалить только стандартные записи hello-world, sample-page, privacy-policy')
    p.add_argument('--replace-content', action='store_true', help='после резервной копии заменить все записи, вложения и меню целевого WP содержимым пакета')
    p.add_argument('--timeout', type=int, default=300, help='тайм-аут обычной команды, секунд')
    p.add_argument('--import-timeout', type=int, default=1800)
    p.add_argument('--importer-version', help='версия временного wordpress-importer; иначе из settings.json или текущая из WordPress.org')
    p.add_argument('--backup-dir', type=Path, default=Path.home() / 'dtpstat-backups')
    p.add_argument('--log-dir', type=Path, default=Path.home() / 'dtpstat-install-logs')
    args = p.parse_args()
    if min(args.timeout, args.import_timeout) < 1:
        p.error('тайм-ауты должны быть положительными')
    if os.geteuid() == 0 and not args.allow_root:
        p.error('запустите от владельца файлов WP; для осознанного запуска root есть --allow-root')
    wp_path = args.wp_path.resolve(strict=True)
    require_file(wp_path / 'wp-load.php')
    for tool in ('php', 'rsync'):
        if not shutil.which(tool):
            raise RuntimeError(f'Не найдена команда {tool}')
    command = [args.wp_bin]
    if args.wp_cli_phar:
        require_file(args.wp_cli_phar.resolve())
        command = ['php', str(args.wp_cli_phar.resolve())]
    elif not shutil.which(args.wp_bin):
        raise RuntimeError('Не найден wp; задайте --wp-bin или --wp-cli-phar')
    command += [f'--path={wp_path}', '--no-color']
    if args.allow_root:
        command += ['--allow-root']
    args.log_dir = args.log_dir.resolve()
    args.backup_dir = args.backup_dir.resolve()
    for folder in (args.log_dir, args.backup_dir):
        if within(folder, wp_path):
            raise RuntimeError(f'Журналы/резервные копии должны лежать вне WP: {folder}')
    args.log_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    log_path = args.log_dir / (dt.datetime.now().strftime('%Y%m%d-%H%M%S-') + str(os.getpid()) + '.log')
    LOG = log_path.open('x', encoding='utf-8')
    os.chmod(log_path, 0o600)
    log(f'Журнал: {log_path}')
    # Одна блокировка на путь WP; check не создаёт файлов внутри сайта.
    digest = hashlib.sha256(str(wp_path).encode()).hexdigest()
    lock_path = Path(tempfile.gettempdir()) / f'dtpstat-install-{digest}.lock'
    fd = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise RuntimeError('Для этого WordPress уже запущен установщик')
        install(args, command, wp_path)


def install(args, command, wp_path):
    global MUTATIONS_STARTED
    content_policy = 'replace' if args.replace_content else ('yes' if args.remove_stock_content else 'no')
    def wp(*words, capture=False, long=False):
        return run(command + list(words), args.import_timeout if long else args.timeout, capture)

    helper = ROOT / 'bin/install-wp.php'
    settings = ROOT / 'content/settings.json'
    attachments = ROOT / 'content/attachments.json'
    uploads = ROOT / 'content/uploads'
    theme = ROOT / 'theme/dtpstat-child'
    stage('1. Пакет и зависимости')
    for file in (helper, settings, attachments, ROOT / 'content/export.xml', theme / 'style.css'):
        require_file(file)
    if not uploads.is_dir():
        raise RuntimeError('Нет content/uploads')
    d = json.loads(settings.read_text(encoding='utf-8'))
    items = json.loads(attachments.read_text(encoding='utf-8'))
    if not isinstance(items, list):
        raise RuntimeError('attachments.json должен содержать массив')
    ids = []
    for item in items:
        media_id = item['ID']
        if type(media_id) is not int or media_id < 1 or media_id in ids:
            raise RuntimeError(f'Некорректный/повторный ID: {media_id}')
        ids.append(media_id)
        source_path(uploads, item['meta']['_wp_attached_file'])
        meta = item['meta'].get('_wp_attachment_metadata', {})
        if isinstance(meta, dict):
            folder = str(Path(item['meta']['_wp_attached_file']).parent)
            sizes = meta.get('sizes') or {}
            if not isinstance(sizes, dict):
                raise RuntimeError(f'Некорректный список размеров у ID={media_id}')
            for size in sizes.values():
                source_path(uploads, str(Path(folder) / size['file']))
            if meta.get('original_image'):
                source_path(uploads, str(Path(folder) / meta['original_image']))
    # Проверяем XML полностью до изменений; ET сохраняет текст блоков и CDATA как текст.
    for _, ns in ET.iterparse(ROOT / 'content/export.xml', events=['start-ns']):
        try:
            ET.register_namespace(*ns)
        except ValueError:
            pass
    tree = ET.parse(ROOT / 'content/export.xml')
    channel = tree.getroot().find('channel')
    if channel is None:
        raise RuntimeError('WXR: отсутствует channel')
    wxr_media = []
    for node in list(channel.findall('item')):
        post_type = next((n.text for n in node if n.tag.endswith('}post_type')), None)
        if not post_type:
            raise RuntimeError('WXR item без post_type')
        if post_type == 'attachment':
            wxr_media.append(int(next(n.text for n in node if n.tag.endswith('}post_id'))))
            channel.remove(node)
    if set(wxr_media) != set(ids):
        raise RuntimeError('Набор ID медиа в WXR и attachments.json различается')
    run(['php', '-l', str(helper)], args.timeout)
    run(['php', '-r', '$need=["mysqli","json","libxml","SimpleXML","xml","zlib"]; '
         '$bad=array_values(array_filter($need,fn($e)=>!extension_loaded($e))); '
         'if($bad){fwrite(STDERR,"Нет PHP extensions: ".implode(", ",$bad).PHP_EOL);exit(1);} '
         'echo "PHP ".PHP_VERSION."; extensions OK".PHP_EOL;'], args.timeout)
    wp('cli', 'version')
    wp('core', 'is-installed')
    info = json_output(wp('eval-file', str(helper), 'preflight', str(settings),
                          str(attachments), content_policy, capture=True))
    url = args.url or info['home']
    from urllib.parse import urlsplit
    parsed = urlsplit(url)
    if parsed.scheme not in ('http', 'https') or not parsed.netloc or parsed.username or parsed.query or parsed.fragment:
        raise RuntimeError('Некорректный --url; нужен адрес http(s) без credentials, query и fragment')
    url = url.rstrip('/')
    log(f'Цель: {wp_path}; URL: {url}; владелец текущего процесса: uid={os.geteuid()}')
    log('Neve: ' + info['neve_version'])
    for plugin in info['plugins']:
        log(f'Плагин: {plugin["name"]} {plugin["version"]}')
    for row in info['stock']:
        log(f'Будет заменена запись: ID={row["ID"]}, type={row.get("post_type", "?")}, {row["post_name"]}')
    if info['home'].rstrip('/') != info['siteurl'].rstrip('/'):
        raise RuntimeError('home и siteurl различаются: установка с отдельным каталогом ядра требует отдельного сценария')
    for key in ('themes', 'uploads', 'content', 'plugins_dir'):
        target = Path(info[key]).resolve()
        if within(target, ROOT) or within(ROOT, target):
            raise RuntimeError(f'Источник и цель пересекаются: {target}')
        ancestor = target
        while not ancestor.exists():
            ancestor = ancestor.parent
        if not os.access(ancestor, os.W_OK | os.X_OK):
            raise RuntimeError(f'Нет прав записи: {target}')
        if key != 'content' and not within(target, Path(info['content'])):
            raise RuntimeError(f'Каталог вне wp-content не покрывается резервной копией: {target}')
    if not (shutil.which('mysqldump') or shutil.which('mariadb-dump')):
        raise RuntimeError('Для резервной копии нужен mysqldump или mariadb-dump')
    content = Path(info['content']).resolve()
    if within(args.backup_dir, content) or within(args.log_dir, content):
        raise RuntimeError('Резервные копии и журналы не должны лежать внутри wp-content')
    theme_destination = Path(info['themes']) / 'dtpstat-child'
    config_output = content / 'dtpstat-config.php'
    config_command = [sys.executable, str(ROOT / 'bin/generate-config.py'),
                      '--env-file', str(args.env_file), '--output', str(config_output)]
    # Выявляем ошибки .env ДО резервной копии и любых изменений сайта.
    run(config_command + ['--check'], args.timeout)
    needed, theme_link = inspect_content(content, theme, theme_destination)
    for directory in (theme, uploads):
        if any(path.is_symlink() for path in directory.rglob('*')):
            raise RuntimeError(f'В источнике есть symlink: {directory}')
    backup_parent = args.backup_dir
    while not backup_parent.exists():
        backup_parent = backup_parent.parent
    if shutil.disk_usage(backup_parent).free < needed + 128 * 1024**2:
        raise RuntimeError('Недостаточно места для копии wp-content + резерва 128 MiB (размер БД проверяется при экспорте)')
    log(f'Исходных медиа: {len(items)}; резервная копия wp-content: {needed / 1024**2:.1f} MiB + БД')
    if args.check:
        log('Предварительные проверки пройдены. Сайт не изменён. Сетевые загрузки проверяются при установке.')
        return

    stage('2. Резервная копия до изменений')
    args.backup_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    backup = Path(tempfile.mkdtemp(prefix=dt.datetime.now().strftime('%Y%m%d-%H%M%S-'), dir=args.backup_dir))
    log(f'Резервная копия: {backup}; при ошибке автоматического отката нет')
    wp('db', 'export', str(backup / 'database.sql'), long=True)
    # Проверенная ссылка темы раскрывается только в резервной копии.
    run(['rsync', '-a', '--copy-links', '--no-owner', '--no-group', '--info=progress2', str(content) + '/', str(backup / 'wp-content') + '/'], args.import_timeout)
    if (wp_path / '.htaccess').exists():
        shutil.copy2(wp_path / '.htaccess', backup / 'htaccess')
    (backup / 'manifest.json').write_text(json.dumps(dict(wp_path=str(wp_path), theme_symlink=theme_link, **info), ensure_ascii=False, indent=2), encoding='utf-8')
    # Проверки повторяем непосредственно перед первой мутацией.
    json_output(wp('eval-file', str(helper), 'preflight', str(settings), str(attachments),
                   content_policy, capture=True))
    stage('Генерация PHP-констант из проектного .env')
    MUTATIONS_STARTED = True
    run(config_command, args.timeout)
    stage('3. Подготовка контента и язык')
    MUTATIONS_STARTED = True
    wp('eval-file', str(helper), 'prepare', str(settings), str(attachments), content_policy)
    wp('language', 'core', 'install', 'ru_RU', '--activate')
    stage('4. Тема и плагины с версиями из settings.json')
    wp('theme', 'install', 'neve', '--version=' + info['neve_version'], '--force')
    sync_theme(theme, theme_destination, args.import_timeout)
    wp('theme', 'activate', 'dtpstat-child')
    for plugin in info['plugins']:
        wp('plugin', 'install', plugin['name'], '--version=' + plugin['version'], '--force', '--activate')
    # Существующий импортёр сохраняем; временный удаляем только после полного успеха.
    if not info['importer_installed']:
        version = args.importer_version or next((p['version'] for p in d['plugins'] if p['name'] == 'wordpress-importer'), None)
        extra = ['--version=' + version] if version else []
        wp('plugin', 'install', 'wordpress-importer', *extra)
    wp('plugin', 'activate', 'wordpress-importer')
    stage('5. Файлы uploads, включая ручные кропы')
    run(['rsync', '-rlt', '--chmod=D755,F644', '--info=progress2', str(uploads) + '/',
         info['uploads'] + '/'], args.import_timeout)
    stage('6. Медиа с исходными ID и метаданными')
    wp('eval-file', str(helper), 'restore', str(settings), str(attachments), long=True)
    stage('7. WXR без вложений')
    with tempfile.TemporaryDirectory(prefix='dtpstat-wxr-') as directory:
        filtered = Path(directory) / 'import.xml'
        tree.write(filtered, encoding='utf-8', xml_declaration=True)
        wp('import', str(filtered), '--authors=create', '--user=' + str(info['admin_id']), long=True)
    stage('8. URL, настройки, категории и меню')
    wp('option', 'update', 'home', url)
    wp('option', 'update', 'siteurl', url)
    if d['source_url'].rstrip('/') != url:
        wp('search-replace', d['source_url'].rstrip('/'), url, '--all-tables-with-prefix', '--precise', '--report-changed-only', long=True)
    wp('eval-file', str(helper), 'configure', str(settings), str(attachments))
    stage('9. Реврайты, кэш и проверка результата через WP')
    wp('rewrite', 'flush', '--hard')
    wp('cache', 'flush')
    wp('eval-file', str(helper), 'verify', str(settings), str(attachments), url, long=True)
    if not info['importer_installed']:
        wp('plugin', 'deactivate', 'wordpress-importer')
        wp('plugin', 'delete', 'wordpress-importer')
    elif not info['importer_active']:
        wp('plugin', 'deactivate', 'wordpress-importer')
    log(f'Готово: {url}\nРезервная копия: {backup}')


if __name__ == '__main__':
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    try:
        check_python(sys.version_info)
        main()
    except KeyboardInterrupt:
        stop_child()
        log(failure_message('ПРЕРВАНО'))
        sys.exit(130)
    except Exception as error:
        stop_child()
        log(failure_message('ОШИБКА', str(error)))
        sys.exit(1)
    finally:
        if LOG:
            LOG.close()
