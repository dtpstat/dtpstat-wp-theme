import importlib.util
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
GENERATOR = ROOT / 'bin/generate-config.py'
spec = importlib.util.spec_from_file_location('dtp_generated_config', GENERATOR)
config = importlib.util.module_from_spec(spec)
spec.loader.exec_module(config)

SAMPLE = """# Site-specific public endpoints
DTP_MAP_URL=https://map.example.org/
DTP_FRONTEND_URL=https://frontend.example.org/
DTP_CONTACT_EMAIL=team@example.org
DTP_CONTACT_TELEGRAM_LABEL='O\\'Reilly'
"""


class GeneratedConfigTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.env = self.root / '.env'
        self.wp_content = self.root / 'wp-content'
        self.wp_content.mkdir()
        self.output = self.wp_content / config.OUTPUT_NAME

    def write_env(self, contents=None):
        self.env.write_text(contents if contents is not None else SAMPLE, encoding='utf-8')

    def call(self, *options):
        return subprocess.run(
            [sys.executable, str(GENERATOR), '--env-file', str(self.env),
             '--output', str(self.output), *options],
            text=True, capture_output=True,
        )

    def test_check_does_not_write_files(self):
        self.write_env()
        response = self.call('--check')
        self.assertEqual(response.returncode, 0, response.stderr)
        self.assertFalse(self.output.exists())

    def test_generated_php_is_valid_and_constants_match(self):
        self.write_env(SAMPLE.replace("O\\'Reilly", "O'Reilly"))
        response = self.call()
        self.assertEqual(response.returncode, 0, response.stderr)
        self.assertTrue(self.output.is_file())
        text = self.output.read_text(encoding='utf-8')
        self.assertIn(config.MARKER, text)
        self.assertNotIn('getenv(', text)
        if shutil.which('php'):
            lint = subprocess.run(['php', '-l', str(self.output)], capture_output=True, text=True)
            self.assertEqual(lint.returncode, 0, lint.stderr)
            probe = subprocess.run([
                'php', '-r',
                "define('ABSPATH','/'); require $argv[1]; "
                "echo DTP_MAP_URL,'|',DTP_FRONTEND_URL,'|',DTP_CONTACT_TELEGRAM_LABEL;",
                str(self.output),
            ], capture_output=True, text=True)
            self.assertEqual(probe.returncode, 0, probe.stderr)
            self.assertEqual(probe.stdout, "https://map.example.org|https://frontend.example.org|O'Reilly")

    def test_duplicate_keys_rejected(self):
        self.write_env(SAMPLE + '\nDTP_MAP_URL=https://duplicate.example.org\n')
        self.assertNotEqual(self.call('--check').returncode, 0)

    def test_unknown_keys_not_exported(self):
        self.write_env(SAMPLE + '\nDATABASE_PASSWORD=secret\n')
        result = self.call('--check')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('DATABASE_PASSWORD', result.stderr)

    def test_required_map_and_details_urls(self):
        self.write_env('DTP_MAP_URL=https://map.example.org\n')
        result = self.call('--check')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('DTP_FRONTEND_URL', result.stderr)

    def test_reject_injection_and_non_http_urls(self):
        for entry in (
            'DTP_MAP_URL=javascript:alert(1)',
            'DTP_MAP_URL=https://user:pass@example.org',
            'DTP_MAP_URL=https://example.org?q=1',
            'DTP_MAP_URL=https://example.org:99999',
        ):
            with self.subTest(entry=entry):
                self.write_env(entry + '\nDTP_FRONTEND_URL=https://frontend.example.org\n')
                self.assertNotEqual(self.call('--check').returncode, 0)

    def test_invalid_change_preserves_previous_file(self):
        self.write_env()
        self.assertEqual(self.call().returncode, 0)
        before = self.output.read_bytes()
        self.write_env('DTP_MAP_URL=javascript:alert(1)\n')
        self.assertNotEqual(self.call().returncode, 0)
        self.assertEqual(before, self.output.read_bytes())

    def test_refuses_unmanaged_existing_file_and_symlink(self):
        self.write_env()
        self.output.write_text('<?php echo "hand-written";', encoding='utf-8')
        self.assertNotEqual(self.call('--check').returncode, 0)
        self.output.unlink()
        alternative = self.root / 'other.php'
        alternative.write_text('<?php', encoding='utf-8')
        self.output.symlink_to(alternative)
        self.assertNotEqual(self.call('--check').returncode, 0)
        self.assertEqual(alternative.read_text(), '<?php')


if __name__ == '__main__':
    unittest.main()
