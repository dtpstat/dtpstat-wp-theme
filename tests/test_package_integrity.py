"""Static consistency checks of the committed WordPress export package.

These tests do not need WordPress, MySQL, WP-CLI or network access.
"""
import json
from pathlib import Path
import unittest
import xml.etree.ElementTree as ET

BASE = Path(__file__).resolve().parents[1]
CONTENT = BASE / 'content'
WP_NS = {'wp': 'http://wordpress.org/export/1.2/'}


class PackageIntegrityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.settings = json.loads((CONTENT / 'settings.json').read_text(encoding='utf-8'))
        cls.attachments = json.loads((CONTENT / 'attachments.json').read_text(encoding='utf-8'))
        cls.root = ET.parse(CONTENT / 'export.xml').getroot()
        cls.channel = cls.root.find('channel')
        if cls.channel is None:
            raise AssertionError('WXR is missing channel')
        cls.items = cls.channel.findall('item')

    def test_attachment_manifest_matches_settings(self):
        ids = [item['ID'] for item in self.attachments]
        self.assertEqual(len(ids), len(set(ids)), 'Repeated ID in attachments.json')
        self.assertTrue(all(type(media_id) is int and media_id > 0 for media_id in ids))
        self.assertEqual(len(ids), self.settings['counts']['media'])

    def test_wxr_attachment_id_set_matches_manifest(self):
        # WXR may contain repeated attachment items. The installer removes all
        # of them before import and compares their distinct IDs with JSON.
        xml_ids = []
        for item in self.items:
            if item.findtext('wp:post_type', namespaces=WP_NS) == 'attachment':
                xml_ids.append(int(item.findtext('wp:post_id', namespaces=WP_NS)))
        self.assertEqual(set(xml_ids), {item['ID'] for item in self.attachments})

    def test_export_source_matches_settings(self):
        self.assertEqual(
            self.channel.findtext('wp:base_site_url', namespaces=WP_NS).rstrip('/'),
            self.settings['source_url'].rstrip('/'),
        )

    def test_export_counts_match_manifest(self):
        types = {
            'pages': ('page', 'publish'),
            'drafts': ('page', 'draft'),
            'posts': ('post', 'publish'),
            'persons': ('dtp_person', None),
            'sponsors': ('dtp_sponsor', None),
        }
        for key, (post_type, status) in types.items():
            actual = 0
            for item in self.items:
                if item.findtext('wp:post_type', namespaces=WP_NS) != post_type:
                    continue
                current_status = item.findtext('wp:status', namespaces=WP_NS)
                if status is not None and current_status != status:
                    continue
                if status is None and current_status in ('trash', 'auto-draft'):
                    continue
                actual += 1
            with self.subTest(key=key):
                self.assertEqual(actual, self.settings['counts'][key])

    def test_all_declared_media_files_exist(self):
        uploads = CONTENT / 'uploads'
        root = uploads.resolve()
        for item in self.attachments:
            media_id = item['ID']
            rel = item['meta']['_wp_attached_file']
            metadata = item['meta'].get('_wp_attachment_metadata') or {}
            filenames = [rel]
            if isinstance(metadata, dict):
                folder = Path(rel).parent
                for size in (metadata.get('sizes') or {}).values():
                    filenames.append(str(folder / size['file']))
                if metadata.get('original_image'):
                    filenames.append(str(folder / metadata['original_image']))
            for filename in filenames:
                with self.subTest(id=media_id, file=filename):
                    candidate = uploads / filename
                    self.assertFalse(Path(filename).is_absolute())
                    self.assertTrue(candidate.resolve().is_relative_to(root))
                    self.assertTrue(candidate.is_file(), f'Missing upload: {filename}')


if __name__ == '__main__':
    unittest.main()
