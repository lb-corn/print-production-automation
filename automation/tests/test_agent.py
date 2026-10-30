import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch, Mock

SPEC = importlib.util.spec_from_file_location('agent', Path(__file__).resolve().parents[1] / 'factory_agent.py')
agent = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(agent)


def batch(quantity=1):
    return {'id': 1, 'batch_id': 'LB-TEST', 'status': 'HANDOFF_REQUESTED', 'handoff_authorized': True,
            'payload': {'batch_id': 'LB-TEST', 'order_count': 1, 'artwork_file_count': 1,
                        'orders': [{'line_item_id': 'line-1', 'order': '#100', 'quantity': quantity,
                                    'series_code': 'PSE', 'artworks': [{'material': 'Rave 9', 'side': 'PRINT',
                                    'filename': 'approved.png', 'drive_file_id': 'drive_test_123'}]}]}}


class AgentSecurityTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='lb-agent-test-')
        self.root = Path(self.temp.name)
        self.out = self.root / 'workspace'; self.out.mkdir()
        self.hot = self.root / 'hot'; self.hot.mkdir()
        self.patches = [patch.object(agent, 'OUT', self.out), patch.object(agent, 'HOT', str(self.hot))]
        for item in self.patches: item.start()

    def tearDown(self):
        for item in self.patches: item.stop()
        self.temp.cleanup()

    def prepare_fixture(self, item=None):
        item = item or batch()
        files = agent.validate_batch(item)
        folder = agent.batch_folder(item); folder.mkdir()
        for file in files:
            path = agent.contained(folder, file['relative']); path.parent.mkdir(exist_ok=True)
            path.write_bytes(b'\x89PNG\r\n\x1a\n' + b'test artwork bytes')
            file['sha256'] = agent.digest(path); file['bytes'] = path.stat().st_size
        agent.write_json(folder / 'batch.json', {'payload_sha256': agent.payload_hash(item), 'files': files})
        return item, folder

    def test_path_traversal_rejected(self):
        with self.assertRaises(ValueError): agent.contained(self.out, '../outside')
        item = batch(); item['batch_id'] = '../outside'
        with self.assertRaises(ValueError): agent.validate_batch(item)
        self.assertEqual(agent.safe('CON.txt'), '_CON.txt')

    def test_executable_and_spoofed_image_rejected(self):
        item = batch(); item['payload']['orders'][0]['artworks'][0]['filename'] = 'attack.exe'
        with self.assertRaises(ValueError): agent.validate_batch(item)
        path = self.out / 'fake.png'; path.write_bytes(b'MZ executable')
        with self.assertRaises(ValueError): agent.check_magic(path)

    def test_empty_and_wrong_count_batches_rejected(self):
        item = batch(); item['payload']['orders'] = []
        with self.assertRaises(ValueError): agent.validate_batch(item)
        item = batch(); item['payload']['artwork_file_count'] = 2
        with self.assertRaises(ValueError): agent.validate_batch(item)

    def test_hash_and_payload_tampering_rejected(self):
        item, folder = self.prepare_fixture()
        _, files = agent.verify_prepared_batch(item)
        files[0].write_bytes(b'\x89PNG\r\n\x1a\nchanged')
        with self.assertRaises(ValueError): agent.verify_prepared_batch(item)
        item['payload']['orders'][0]['quantity'] = 2
        with self.assertRaises(ValueError): agent.verify_prepared_batch(item)

    def test_extra_file_rejected(self):
        item, folder = self.prepare_fixture(); (folder / 'extra.exe').write_bytes(b'MZ')
        with self.assertRaises(ValueError): agent.verify_prepared_batch(item)

    def test_symlink_rejected_when_available(self):
        target = self.root / 'outside'; target.mkdir()
        link = self.out / 'link'
        try: link.symlink_to(target, target_is_directory=True)
        except OSError: self.skipTest('OS does not permit unprivileged symlinks')
        with self.assertRaises(ValueError): agent.contained(self.out, 'link/file')

    def test_http_and_placeholder_keys_rejected(self):
        with patch.object(agent, 'BASE', 'http://example.test/api'), patch.object(agent, 'KEY', 'a' * 64):
            with self.assertRaises(ValueError): agent.integrity()
        with patch.object(agent, 'BASE', 'https://example.test/api'), patch.object(agent, 'KEY', 'CHANGE_ME'):
            with self.assertRaises(ValueError): agent.integrity()

    def test_redirect_blocked_without_forwarding_key(self):
        response = Mock(status_code=302); response.__enter__ = Mock(return_value=response); response.__exit__ = Mock(return_value=False)
        with patch.object(agent.SESSION, 'request', return_value=response) as request:
            with self.assertRaises(RuntimeError): agent.api('GET', '/factory/batches/next')
            self.assertFalse(request.call_args.kwargs['allow_redirects'])
            self.assertFalse(agent.SESSION.trust_env)

    def test_missing_human_confirmation_blocks_handoff(self):
        item, folder = self.prepare_fixture(); item['handoff_authorized'] = False
        with patch.object(agent, 'status'), patch.object(agent, 'scan_file'):
            with self.assertRaises(ValueError): agent.handoff(item)
        self.assertEqual(list(self.hot.iterdir()), [])

    def test_multiset_handoff_fails_closed(self):
        item, folder = self.prepare_fixture(batch(2))
        with patch.object(agent, 'status'), patch.object(agent, 'scan_file'):
            with self.assertRaises(RuntimeError): agent.handoff(item)
        self.assertEqual(list(self.hot.iterdir()), [])

    def test_clean_handoff_and_consumed_files_never_republish(self):
        item, folder = self.prepare_fixture()
        with patch.object(agent, 'status') as status, patch.object(agent, 'scan_file'):
            agent.handoff(item)
            files = list(self.hot.iterdir()); self.assertEqual(len(files), 1)
            self.assertEqual(files[0].suffix, '.png')
            files[0].unlink()  # Simulate Edge Print consuming the artwork.
            agent.handoff(item)
            self.assertEqual(list(self.hot.iterdir()), [])
            self.assertEqual(status.call_args.args, (1, 'SENT_TO_RIP'))

    def test_interrupted_handoff_never_republishes(self):
        item, folder = self.prepare_fixture()
        agent.write_json(folder / 'handoff.json', {'state': 'publishing', 'payload_sha256': agent.payload_hash(item)})
        with patch.object(agent, 'status'), patch.object(agent, 'scan_file'):
            with self.assertRaises(RuntimeError): agent.handoff(item)
        self.assertEqual(list(self.hot.iterdir()), [])

    def test_defender_cannot_be_disabled(self):
        with patch.object(agent, 'SCAN', False):
            with self.assertRaises(RuntimeError): agent.scan_file(self.out / 'art.png')


if __name__ == '__main__': unittest.main()
