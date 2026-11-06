import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch

spec = importlib.util.spec_from_file_location('portal_agent', Path(__file__).resolve().parents[1] / 'factory_agent.py')
agent = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agent)
TOKEN = '4d5e1970-f1d1-4638-b9c0-58841adc7548'


class PortalDispatchTests(unittest.TestCase):
    def test_launcher_accepts_only_fixed_local_worker_and_typed_identifiers(self):
        with patch.object(agent.subprocess, 'Popen') as launch:
            agent.launch_assigned_worker({'batch_id': 12, 'phase': 'prepare', 'token': TOKEN})
            args = launch.call_args.args[0]
            self.assertEqual(args, [agent.sys.executable, '-I', str(agent.HERE / 'factory_agent.py'), '--managed-job', '12', TOKEN])
            self.assertFalse(launch.call_args.kwargs['shell'])
            for bad in [{'batch_id': '12; shutdown', 'phase': 'prepare', 'token': TOKEN}, {'batch_id': 12, 'phase': 'execute', 'token': TOKEN}, {'batch_id': 12, 'phase': 'prepare', 'token': 'invalid'}]:
                with self.assertRaises((ValueError, KeyError)): agent.launch_assigned_worker(bad)
            self.assertEqual(launch.call_count, 1)

    def test_assigned_worker_runs_one_phase_then_returns(self):
        with patch.object(agent, 'api', return_value={'batch': {'id':12,'status':'CLAIMED'}}), patch.object(agent, 'validate_batch'), patch.object(agent, 'prepare') as prepare, patch.object(agent, 'handoff') as handoff:
            agent.assigned_once(12)
            prepare.assert_called_once_with({'id':12,'status':'CLAIMED'}, already_claimed=True)
            handoff.assert_not_called()
        with patch.object(agent, 'api', return_value={'batch': {'id':12,'status':'HANDOFF_REQUESTED'}}), patch.object(agent, 'validate_batch'), patch.object(agent, 'status') as status, patch.object(agent, 'handoff') as handoff:
            agent.assigned_once(12)
            status.assert_called_once_with(12,'DELIVERING')
            handoff.assert_called_once()

    def test_listener_does_not_relaunch_uncertain_phase(self):
        with tempfile.TemporaryDirectory() as temp, patch.object(agent, 'OUT', Path(temp)), patch.object(agent, 'STOP', False), patch.object(agent, 'worker_available', return_value=True), patch.object(agent, 'send_heartbeat'), patch.object(agent, 'launch_assigned_worker') as launch:
            journal=Path(temp)/'portal-operation.json'
            journal.write_text(json.dumps({'batch_id':12,'phase':'handoff','state':'requesting'}))
            def sleep(_): agent.STOP=True
            with patch.object(agent.time,'sleep',side_effect=sleep), patch.object(agent,'api',return_value={'job':{'id':12,'phase':'handoff'}}) as api:
                agent.listen_for_portal()
                self.assertEqual(api.call_count,1)
                launch.assert_not_called()

    def test_listener_waits_for_local_worker_lock(self):
        with patch.object(agent,'STOP',False), patch.object(agent,'worker_available',return_value=False), patch.object(agent,'api') as api, patch.object(agent,'send_heartbeat') as heartbeat:
            def sleep(_): agent.STOP=True
            with patch.object(agent.time,'sleep',side_effect=sleep): agent.listen_for_portal()
            api.assert_not_called();heartbeat.assert_not_called()

    def test_listener_claims_only_server_authorized_offer(self):
        with tempfile.TemporaryDirectory() as temp, patch.object(agent,'OUT',Path(temp)), patch.object(agent,'STOP',False), patch.object(agent,'worker_available',return_value=True), patch.object(agent,'send_heartbeat'):
            operation={'batch_id':12,'phase':'prepare','token':TOKEN}
            def launch(op):
                self.assertEqual(op,operation);agent.STOP=True;return Mock()
            with patch.object(agent,'api',side_effect=[{'job':{'id':12,'phase':'prepare'}},{'operation':operation}]) as api, patch.object(agent,'launch_assigned_worker',side_effect=launch):
                agent.listen_for_portal()
                self.assertEqual(api.call_args_list[1].args,('POST','/factory/batches/12/begin-work'))
                self.assertEqual(json.loads((Path(temp)/'portal-operation.json').read_text())['state'],'starting')

    def test_preclaim_cannot_be_used_by_legacy_worker(self):
        with patch.object(agent,'validate_batch',return_value=[{}]), patch.object(agent,'PORTAL_MODE',False), patch.object(agent,'status') as status:
            with self.assertRaises(ValueError): agent.prepare({'id':12,'status':'CLAIMED'},already_claimed=True)
            status.assert_not_called()
