import contextlib
import hashlib
import io
import json
from pathlib import Path
import re
import sys
import tempfile
import unittest
from unittest.mock import MagicMock, patch

SCRIPT = (Path(__file__).resolve().parents[1] / 'report-connection.ps1').read_text(encoding='utf-8-sig')
SOURCE = re.search(r"\$Reporter = @'\r?\n([\s\S]*?)\r?\n'@", SCRIPT).group(1)


class ConnectionReporterTests(unittest.TestCase):
    def run_report(self, status=200):
        with tempfile.TemporaryDirectory(prefix='lb-connection-') as folder:
            root = Path(folder)
            (root / '.env').write_text('PRODUCTION_API=https://example.invalid/api\nFACTORY_API_KEY=' + 'f' * 64 + '\n')
            (root / 'VERSION').write_text('2.9.7')
            (root / 'factory_agent.py').write_bytes(b'installed PC source')
            (root / 'service-account.json').write_text('PRIVATE-CREDENTIAL-DO-NOT-TRANSMIT')
            response = MagicMock(status_code=status);response.__enter__.return_value = response
            response.iter_content.return_value = [b'{"ok":true}']
            session = MagicMock();session.post.return_value = response
            with (patch('requests.Session', return_value=session), patch.object(sys, 'argv', ['reporter', folder, 'once']),
                  contextlib.redirect_stdout(io.StringIO()) as output):
                with self.assertRaises(SystemExit) as stopped:
                    exec(compile(SOURCE, 'report-connection.ps1', 'exec'), {})
            return session, stopped.exception.code, output.getvalue()

    def test_existing_installation_reports_its_actual_version_and_hash_without_batch_calls(self):
        session, code, output = self.run_report()
        self.assertEqual(code, 0)
        session.post.assert_called_once()
        self.assertEqual(session.post.call_args.args[0], 'https://example.invalid/api/factory/heartbeat')
        data = session.post.call_args.kwargs['json']
        self.assertEqual(data['agent_version'], '2.9.7')
        self.assertEqual(data['source_sha256'], hashlib.sha256(b'installed PC source').hexdigest())
        self.assertEqual(data['reporter_mode'], 'connection_only')
        self.assertIsNone(data['batch_id'])
        self.assertTrue(session.post.call_args.kwargs['verify'])
        self.assertFalse(session.post.call_args.kwargs['allow_redirects'])
        session.get.assert_not_called()
        self.assertNotIn('PRIVATE-CREDENTIAL', json.dumps(data))
        self.assertNotIn('f' * 64, output)

    def test_rejected_report_returns_failure_without_starting_any_other_operation(self):
        session, code, output = self.run_report(401)
        self.assertEqual(code, 1)
        session.post.assert_called_once();session.get.assert_not_called()
        self.assertIn('401', output)


if __name__ == '__main__': unittest.main()
