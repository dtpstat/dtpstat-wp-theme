import argparse
import importlib.util
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('deploy_test_module', ROOT / 'bin/deploy.py')
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)


class DeployTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        base = Path(self.directory.name)
        self.repo = base / 'repo'
        self.wp = base / 'wordpress'
        self.content = self.wp / 'wp-content'
        self.themes = self.content / 'themes'
        self.theme = self.repo / 'theme/dtpstat-child'
        self.theme.mkdir(parents=True)
        self.themes.mkdir(parents=True)
        (self.wp / 'wp-load.php').write_text('<?php', encoding='utf-8')
        (self.theme / 'functions.php').write_text('<?php // v1\n', encoding='utf-8')
        (self.theme / 'style.css').write_text('/*\nTheme Name: dtpstat-child\n*/\n', encoding='utf-8')
        self.env = self.repo / '.env'
        self.env.write_text(
            'DTP_MAP_URL=https://map.example.org\n'
            'DTP_FRONTEND_URL=https://details.example.org\n',
            encoding='utf-8',
        )
        self.dest = self.themes / 'dtpstat-child'
        self.dest.symlink_to(self.theme)
        self.args = argparse.Namespace(
            wp_path=self.wp, content_dir=None, env_file=self.env, check=False,
            adopt_existing_directory=False,
        )

    def run_deploy(self, check=False):
        with patch.object(deploy, 'ROOT', self.repo):
            self.args.check = check
            deploy.deploy(self.args)

    def test_check_is_read_only(self):
        original = os.readlink(self.dest)
        self.run_deploy(check=True)
        self.assertEqual(os.readlink(self.dest), original)
        self.assertFalse((self.content / 'dtpstat-config.php').exists())
        self.assertFalse((self.content / 'dtpstat-releases').exists())

    def test_deploy_stages_release_compiles_config_and_detaches_git_source(self):
        self.run_deploy()
        actual = self.dest.resolve()
        self.assertNotEqual(actual, self.theme.resolve())
        self.assertIn('dtpstat-releases', str(actual))
        self.assertEqual((actual / 'functions.php').read_text(), '<?php // v1\n')
        conf = self.content / 'dtpstat-config.php'
        self.assertTrue(conf.is_file())
        self.assertIn("define( 'DTP_MAP_URL', 'https://map.example.org' );",
                      conf.read_text())
        (self.theme / 'functions.php').write_text('<?php // v2\n')
        self.assertEqual((actual / 'functions.php').read_text(), '<?php // v1\n')
        self.run_deploy(check=True)
        self.assertEqual(self.dest.resolve(), actual)

    def test_redeploy_unchanged_is_idempotent(self):
        self.run_deploy()
        link = self.dest.resolve()
        config = (self.content / 'dtpstat-config.php').read_bytes()
        self.run_deploy()
        self.assertEqual(self.dest.resolve(), link)
        self.assertEqual((self.content / 'dtpstat-config.php').read_bytes(), config)
        self.assertEqual(len(list((self.content / 'dtpstat-releases').iterdir())), 1)

    def test_plain_directory_is_not_overwritten(self):
        self.dest.unlink()
        self.dest.mkdir()
        (self.dest / 'important.txt').write_text('keep')
        with self.assertRaisesRegex(ValueError, 'обычный каталог'):
            self.run_deploy()
        self.assertEqual((self.dest / 'important.txt').read_text(), 'keep')
        self.assertFalse((self.content / 'dtpstat-config.php').exists())

    def test_physical_directory_migration_requires_flag_and_preserves_old_version(self):
        self.dest.unlink()
        self.dest.mkdir()
        (self.dest / 'style.css').write_text('/* Theme Name: legacy */')
        (self.dest / 'functions.php').write_text('<?php // old')
        (self.dest / 'old-file.txt').write_text('do not discard')
        self.args.adopt_existing_directory = True
        self.run_deploy(check=True)
        self.assertTrue(self.dest.is_dir())
        self.assertFalse(self.dest.is_symlink())
        self.run_deploy()
        self.assertTrue(self.dest.is_symlink())
        backups = list((self.content / 'dtpstat-releases').glob('legacy-*'))
        self.assertEqual(len(backups), 1)
        self.assertEqual((backups[0] / 'old-file.txt').read_text(), 'do not discard')

    def test_unknown_symlink_is_not_overwritten(self):
        self.dest.unlink()
        self.dest.symlink_to(self.wp)
        with self.assertRaisesRegex(ValueError, 'Неизвестная'):
            self.run_deploy()
        self.assertEqual(self.dest.resolve(), self.wp.resolve())

    def test_bad_env_stops_before_mutations(self):
        original = os.readlink(self.dest)
        self.env.write_text('DTP_MAP_URL=javascript:bad\n')
        with self.assertRaises(ValueError):
            self.run_deploy()
        self.assertEqual(os.readlink(self.dest), original)
        self.assertFalse((self.content / 'dtpstat-releases').exists())

    def test_php_lint_failure_stops_before_mutations(self):
        (self.theme / 'functions.php').write_text('<?php function {\n')
        with self.assertRaisesRegex(RuntimeError, 'PHP-синтаксиса'):
            self.run_deploy()
        self.assertFalse((self.content / 'dtpstat-config.php').exists())

    def test_config_not_owned_by_generator_is_not_overwritten(self):
        config = self.content / 'dtpstat-config.php'
        config.write_text('<?php // foreign')
        with self.assertRaisesRegex(ValueError, 'не сгенерирован'):
            self.run_deploy()
        self.assertEqual(config.read_text(), '<?php // foreign')

    def test_bash_entry_syntax(self):
        subprocess.run(['bash', '-n', str(ROOT / 'bin/deploy.sh')],
                       check=True, capture_output=True)

    def test_php_available(self):
        self.assertIsNotNone(shutil.which('php'))


if __name__ == '__main__':
    unittest.main()
