import contextlib
import ast
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET

BASE = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('installer', BASE / 'bin/install.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class InstallerTests(unittest.TestCase):
    def test_project_theme_symlink_is_preserved_and_backed_up_in_size(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            content = base / 'wp-content'
            destination = content / 'themes/dtpstat-child'
            destination.parent.mkdir(parents=True)
            theme = base / 'project/theme/dtpstat-child'
            theme.mkdir(parents=True)
            (theme / 'style.css').write_bytes(b'12345')
            destination.symlink_to(theme)
            with contextlib.redirect_stdout(io.StringIO()):
                size, link = m.inspect_content(content, theme, destination)
            self.assertEqual(size, 5)
            self.assertEqual(link['target'], str(theme))
            self.assertTrue(destination.is_symlink())

    def test_project_theme_sync_does_not_copy_into_itself(self):
        with tempfile.TemporaryDirectory() as directory:
            theme = Path(directory) / 'source'
            theme.mkdir()
            destination = Path(directory) / 'linked'
            destination.symlink_to(theme)
            with patch.object(m, 'run') as run, contextlib.redirect_stdout(io.StringIO()):
                m.sync_theme(theme, destination, 10)
            run.assert_not_called()
            self.assertTrue(destination.is_symlink())

    def test_unknown_content_symlink_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            content = base / 'wp-content'
            content.mkdir()
            (content / 'unrelated').symlink_to(base / 'elsewhere')
            with self.assertRaisesRegex(RuntimeError, 'Неизвестная'):
                m.inspect_content(content, base / 'theme', content / 'themes/dtpstat-child')

    def php_replace(self, mode):
        result = subprocess.run(['php', str(BASE / 'tests/replace_fixture.php'), mode,
                                 str(BASE / 'bin/install-wp.php')], check=True, capture_output=True, text=True)
        return json.loads(result.stdout)

    def test_replace_plans_navigation_and_all_posts_without_deleting(self):
        result = self.php_replace('plan')
        self.assertEqual([r['ID'] for r in result['rows']], [4, 7, 10, 11])
        self.assertEqual(result['remaining'], [4, 7, 10, 11])
        self.assertEqual(result['events'], [])
        self.assertNotIn('WHERE', result['query'])

    def test_strict_mode_still_requires_explicit_replacement(self):
        result = self.php_replace('strict')
        self.assertIn('--replace-content', result['error'])
        self.assertEqual(result['events'], [])

    def test_replace_uses_attachment_api_and_handles_deleted_revisions(self):
        result = self.php_replace('delete')
        self.assertEqual(result['remaining'], [])
        self.assertIn(['attachment', 7], result['events'])
        self.assertNotIn(['post', 7], result['events'])
        self.assertNotIn(['post', 11], result['events'])
        self.assertIn(['term', 'category', 2], result['events'])
        self.assertNotIn(['term', 'category', 1], result['events'])
        self.assertIn(['option', 'page_on_front', 0], result['events'])

    def test_python_388_is_accepted(self):
        m.check_python((3, 8, 8))

    def test_python_37_is_rejected(self):
        with self.assertRaisesRegex(RuntimeError, 'обнаружен 3.7.9'):
            m.check_python((3, 7, 9))

    def test_syntax_is_python38_compatible(self):
        ast.parse((BASE / 'bin/install.py').read_text(), feature_version=(3, 8))

    def test_preflight_error_does_not_claim_partial_install(self):
        with patch.object(m, 'MUTATIONS_STARTED', False):
            message = m.failure_message('ОШИБКА', 'Нет rsync')
        self.assertIn('не начинал изменять сайт', message)
        self.assertNotIn('частично', message)

    def test_install_error_reports_partial_state(self):
        with patch.object(m, 'MUTATIONS_STARTED', True):
            self.assertIn('частично', m.failure_message('ОШИБКА', 'Сбой импорта'))

    def test_arguments_are_literal(self):
        value = 'a b; $(touch should-not-exist) `id` " x'
        with contextlib.redirect_stdout(io.StringIO()):
            result = m.run([sys.executable, '-c', 'import sys;print(sys.argv[1])', value], 5, True)
        self.assertEqual(result.rstrip(), value)

    def test_nonzero_retained(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output), self.assertRaisesRegex(RuntimeError, 'кодом 37'):
            m.run([sys.executable, '-c', 'print("db failed");raise SystemExit(37)'], 5, True)
        self.assertIn('db failed', output.getvalue())

    def test_timeout_stops_child(self):
        with contextlib.redirect_stdout(io.StringIO()), self.assertRaisesRegex(RuntimeError, 'Тайм-аут'):
            m.run([sys.executable, '-c', 'import time;time.sleep(30)'], 1)
        self.assertIsNone(m.CHILD)

    def test_machine_output_can_include_diagnostics(self):
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(m.json_output('notice\nDTPSTAT_JSON:{"x":1}\n'), {'x': 1})

    def test_machine_output_not_silently_empty(self):
        with contextlib.redirect_stdout(io.StringIO()), self.assertRaises(RuntimeError):
            m.json_output('Error: database unavailable')

    def test_duplicate_machine_output_rejected(self):
        with self.assertRaises(RuntimeError):
            m.json_output('DTPSTAT_JSON:{}\nDTPSTAT_JSON:{}')

    def test_upload_traversal_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / 'uploads'
            root.mkdir()
            (Path(directory) / 'outside').write_text('x')
            with self.assertRaisesRegex(RuntimeError, 'выходит'):
                m.source_path(root, '../outside')

    def test_upload_symlink_escape_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / 'uploads'
            root.mkdir()
            outside = Path(directory) / 'outside'
            outside.write_text('x')
            (root / 'linked').symlink_to(outside)
            with self.assertRaisesRegex(RuntimeError, 'выходит'):
                m.source_path(root, 'linked')

    def test_missing_upload_rejected(self):
        with tempfile.TemporaryDirectory() as directory, self.assertRaisesRegex(RuntimeError, 'Нет читаемого'):
            m.source_path(Path(directory), 'missing.jpg')

    def test_empty_and_absolute_upload_paths_rejected(self):
        for value in ('', '/etc/passwd'):
            with self.subTest(value=value), self.assertRaises(RuntimeError):
                m.source_path(Path('/tmp'), value)

    def test_replace_check_never_runs_mutation(self):
        self.test_check_never_runs_mutation(replace=True)

    def test_check_never_runs_mutation(self, replace=False):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            package = base / 'package'
            wp = base / 'wp'
            for path in ('bin', 'content/uploads', 'theme/dtpstat-child'):
                (package / path).mkdir(parents=True)
            for path in ('wp-content/themes', 'wp-content/uploads', 'wp-content/plugins'):
                (wp / path).mkdir(parents=True)
            (package / 'bin/install-wp.php').write_text('<?php')
            (package / 'theme/dtpstat-child/style.css').write_text('Template: neve')
            (package / 'content/settings.json').write_text(json.dumps({'source_url':'https://old.invalid'}))
            (package / 'content/attachments.json').write_text('[]')
            (package / 'content/export.xml').write_text('<rss><channel/></rss>')
            (package / '.env').write_text(
                'DTP_MAP_URL=https://map.example.org\n'
                'DTP_FRONTEND_URL=https://details.example.org\n',
                encoding='utf-8',
            )
            info = dict(home='https://new.invalid',siteurl='https://new.invalid',
                        content=str(wp / 'wp-content'), themes=str(wp / 'wp-content/themes'),
                        uploads=str(wp / 'wp-content/uploads'), plugins_dir=str(wp / 'wp-content/plugins'),
                        stock=[],neve_version='1.0',plugins=[])
            calls = []
            def fake_run(argv, limit, capture=False):
                calls.append(argv)
                if any(str(argument).endswith('/bin/generate-config.py') for argument in argv):
                    self.assertIn('--check', argv, 'dry-run must never generate PHP config')
                    self.assertEqual(Path(argv[argv.index('--env-file') + 1]), package / '.env')
                    self.assertEqual(Path(argv[argv.index('--output') + 1]),
                                     wp / 'wp-content/dtpstat-config.php')
                    return ''
                if 'eval-file' in argv:
                    self.assertIn('preflight', argv)
                    self.assertEqual(argv[-1], 'replace' if replace else 'no')
                    return 'DTPSTAT_JSON:' + json.dumps(info)
                return ''
            import argparse
            args = argparse.Namespace(timeout=5,import_timeout=10,url=None,
                env_file=package / '.env', remove_stock_content=False,
                replace_content=replace, check=True, backup_dir=base / 'backups', log_dir=base / 'logs')
            before = sorted(str(p.relative_to(wp)) for p in wp.rglob('*'))
            with patch.object(m, 'ROOT', package), patch.object(m, 'run', fake_run), patch.object(m.shutil, 'which', return_value='/mock/bin'), contextlib.redirect_stdout(io.StringIO()):
                m.install(args, ['wp', '--path=' + str(wp)], wp)
            self.assertEqual(before, sorted(str(p.relative_to(wp)) for p in wp.rglob('*')))
            self.assertFalse((wp / 'wp-content/dtpstat-config.php').exists())
            self.assertFalse((base / 'backups').exists())
            self.assertEqual(len(calls), 6)
            self.assertEqual(sum(any(str(a).endswith('/bin/generate-config.py') for a in call)
                                 for call in calls), 1)

    def test_wxr_cdata_text_survives_roundtrip(self):
        text = '<!-- wp:image {"id":7} -->\n<figure a="x&y">\\hello</figure>'
        root = ET.fromstring('<rss xmlns:c="urn:content"><channel><item><c:encoded><![CDATA[' + text + ']]></c:encoded></item></channel></rss>')
        reread = ET.fromstring(ET.tostring(root))
        self.assertEqual(reread.find('.//{urn:content}encoded').text, text)

    def test_bash_syntax(self):
        subprocess.run(['bash', '-n', str(BASE / 'bin/install.sh')], check=True)

    def test_php_syntax(self):
        subprocess.run(['php', '-l', str(BASE / 'bin/install-wp.php')], check=True, capture_output=True)


    def test_theme_php_syntax(self):
        for relative in ('theme/dtpstat-child/functions.php', 'theme/dtpstat-child/front-page.php',
                         'theme/dtpstat-child/footer.php', 'tests/theme_env_fixture.php',
                         'tests/theme_styles_fixture.php'):
            with self.subTest(path=relative):
                subprocess.run(['php', '-l', str(BASE / relative)], check=True, capture_output=True)

    def test_theme_removes_neve_main_css_without_removing_child_styles(self):
        result = subprocess.run(
            ['php', str(BASE / 'tests/theme_styles_fixture.php')],
            check=True, capture_output=True, text=True,
        )
        self.assertIn('parent Neve CSS dequeue tests passed', result.stdout)

    def test_theme_external_urls_from_generated_php(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            env = root / '.env'
            env.write_text(
                "DTP_MAP_URL=https://map.example.org/\n"
                "DTP_FRONTEND_URL=https://details.example.org/\n"
                "DTP_CONTACT_EMAIL=team@example.org\n",
                encoding='utf-8',
            )
            output = root / 'dtpstat-config.php'
            subprocess.run(
                [sys.executable, str(BASE / 'bin/generate-config.py'),
                 '--env-file', str(env), '--output', str(output)],
                check=True, capture_output=True, text=True,
            )
            result = subprocess.run(
                ['php', str(BASE / 'tests/theme_env_fixture.php'), str(output)],
                check=True, capture_output=True, text=True,
            )
            self.assertIn('theme compiled PHP config tests passed', result.stdout)


if __name__ == '__main__':
    unittest.main()
