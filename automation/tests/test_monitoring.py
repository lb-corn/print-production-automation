import contextlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import MagicMock, Mock, patch

SPEC = importlib.util.spec_from_file_location('monitored_agent', Path(__file__).resolve().parents[1] / 'factory_agent.py')
agent = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(agent)


class MonitoringTests(unittest.TestCase):
    def test_monitor_form_preserves_false_null_and_nested_diagnostics(self):
        result = agent.monitoring_form({'batch_id':None,'workspace_exists':False,'diagnostics':{'drive_connected':True,'error_code':None}})
        self.assertEqual(result, {'batch_id':'','workspace_exists':'0','diagnostics[drive_connected]':'1','diagnostics[error_code]':''})

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='lb-monitor-')
        self.root = Path(self.temp.name)
        self.out = self.root / 'workspace'; self.out.mkdir()
        self.hot = self.root / 'hot'; self.hot.mkdir()
        (self.root / 'factory_agent.py').write_text('test agent source')
        self.creds = self.root / 'private-credentials.json'; self.creds.write_text('PRIVATE-KEY-DO-NOT-SEND')
        self.patches = [patch.object(agent, 'HERE', self.root), patch.object(agent, 'OUT', self.out),
                        patch.object(agent, 'HOT', str(self.hot)), patch.object(agent, 'CREDS', self.creds),
                        patch.object(agent, 'BASE', 'https://factory.example.invalid/api'), patch.object(agent, 'KEY', 'z' * 64),
                        patch.dict(agent.MONITOR_STATE, {'process_status': 'running', 'stage': 'idle', 'batch_id': None}, clear=True)]
        for item in self.patches: item.start()

    def tearDown(self):
        for item in reversed(self.patches): item.stop()
        self.temp.cleanup()

    def test_identifier_is_stable_and_no_machine_name_is_transmitted(self):
        self.assertEqual(agent.stable_agent_id(), agent.stable_agent_id())
        payload = agent.heartbeat_payload()
        text = json.dumps(payload)
        self.assertEqual(payload['source_sha256'], agent.digest(self.root / 'factory_agent.py'))
        for forbidden in ('PRIVATE-KEY', str(self.root), 'z' * 64, 'client_email', 'hostname', '.env'):
            self.assertNotIn(forbidden, text)

    def test_health_is_read_only_with_tls_no_redirect_and_bounded_timeout(self):
        response = MagicMock(status_code=200)
        response.__enter__.return_value = response
        response.iter_content.return_value = [b'{"ok":true,"authenticated":true}']
        with patch.object(agent.MONITOR_SESSION, 'request', return_value=response) as request, patch.object(agent, 'once') as once:
            data = agent.monitor_request('GET', '/factory/health')
            self.assertTrue(data['authenticated'])
            self.assertEqual(request.call_args.args[:2], ('GET', 'https://factory.example.invalid/api/factory/health'))
            self.assertEqual(request.call_args.kwargs['timeout'], (5, 10))
            self.assertTrue(request.call_args.kwargs['verify'])
            self.assertFalse(request.call_args.kwargs['allow_redirects'])
            once.assert_not_called()
        with self.assertRaises(ValueError): agent.monitor_request('GET', '/factory/batches/next')
        with patch.object(agent, 'BASE', 'http://factory.example.invalid/api'):
            with self.assertRaises(ValueError): agent.monitor_request('GET', '/factory/health')

    def test_monitoring_failure_never_retries_batches_or_leaks_exception(self):
        stop = Mock(); stop.is_set.side_effect = [False, True]
        with (patch.object(agent, 'MONITOR_STOP', stop), patch.object(agent, 'send_heartbeat', side_effect=RuntimeError('PRIVATE-KEY')),
              patch.object(agent, 'once') as once, self.assertLogs(agent.log, level='WARNING') as logs):
            agent.heartbeat_loop()
            once.assert_not_called(); stop.wait.assert_called_once_with(30)
            self.assertNotIn('PRIVATE-KEY', str(logs.output))

    def test_explicit_diagnostic_is_non_destructive_and_does_not_claim_or_print(self):
        before = list(self.hot.iterdir())
        service = Mock()
        process = Mock(returncode=0, stdout='{"AntivirusEnabled":true,"RealTimeProtectionEnabled":true}')
        with (patch.object(agent, 'integrity'), patch.object(agent, 'drive', return_value=service),
              patch.object(agent.subprocess, 'run', return_value=process), patch.object(agent, 'scan_file') as scan,
              patch.object(agent, 'once') as once, patch.object(agent, 'api') as api):
            result = agent.explicit_diagnostics()
            self.assertTrue(result['workspace_writable']); self.assertTrue(result['hot_folder_writable'])
            self.assertTrue(result['drive_connected'])
            service.files.return_value.list.assert_called_once_with(pageSize=1, fields='files(id)', q='trashed = false')
            once.assert_not_called(); api.assert_not_called()
            if agent.os.name == 'nt':
                self.assertTrue(result['defender_scan']); scan.assert_called_once()
        self.assertEqual(list(self.hot.iterdir()), before)
        self.assertEqual(list(self.out.iterdir()), [])
        payload = agent.heartbeat_payload()
        for key in ('printer_ready', 'epson_registered', 'auto_print_off', 'private_key', 'workspace_path'):
            self.assertNotIn(key, payload)

    def test_health_cli_works_without_batch_lock_or_google_diagnostics(self):
        with (patch.object(agent.sys, 'argv', ['agent', '--health']), patch.object(agent, 'monitor_request', return_value={'authenticated': True, 'monitoring_ready': True}) as request,
              patch.object(agent, 'once') as once, patch.object(agent, 'explicit_diagnostics') as diagnostic, contextlib.redirect_stdout(io.StringIO()) as output):
            agent.main()
            request.assert_called_once_with('GET', '/factory/health')
            once.assert_not_called(); diagnostic.assert_not_called()
            self.assertIn('PASSED', output.getvalue())

    def test_unknown_or_combined_modes_cannot_start_processing(self):
        for args in (['--heath'], ['--health', '--once']):
            with (patch.object(agent.sys, 'argv', ['agent', *args]), patch.object(agent, 'once') as once,
                  patch.object(agent, 'monitor_request') as request, contextlib.redirect_stdout(io.StringIO())):
                with self.assertRaises(SystemExit) as stopped:
                    agent.main()
                self.assertEqual(stopped.exception.code, 2)
                once.assert_not_called(); request.assert_not_called()


if __name__ == '__main__': unittest.main()
