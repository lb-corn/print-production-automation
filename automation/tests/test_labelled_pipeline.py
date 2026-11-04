import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from PIL import Image

spec=importlib.util.spec_from_file_location('labelled_agent',Path(__file__).resolve().parents[1]/'factory_agent.py')
agent=importlib.util.module_from_spec(spec);spec.loader.exec_module(agent)


class LabelledPipelineTests(unittest.TestCase):
    def test_labelled_prepare_handoff_retention_and_signed_cleanup(self):
        with tempfile.TemporaryDirectory(prefix='lb-pipeline-') as temporary:
            root=Path(temporary);workspace=root/'workspace';workspace.mkdir();hot=root/'incoming';hot.mkdir()
            batch={'id':19,'batch_id':'LB-PIPELINE-19','status':'CLAIMED','handoff_authorized':True,'payload':{
                'print_label_policy':'BOTTOM_WHITE_V1','batch_id':'LB-PIPELINE-19','order_count':1,'artwork_file_count':1,
                'orders':[{'line_item_id':'line-19','order':'#4019','quantity':1,'artworks':[{'side':'PRINT','material':'Rave 9','filename':'source.png','drive_file_id':'source_drive_19','print_label':'LBSH4019-RAVE9-WHITE-LUCKY-FRIENDS'}]}]}}
            original_payload=json.loads(json.dumps(batch['payload']))
            def download(service,file,destination):
                destination.parent.mkdir(parents=True,exist_ok=True)
                Image.new('RGB',(1000,1000),(30,60,90)).save(destination,dpi=(300,300))
            with patch.object(agent,'OUT',workspace),patch.object(agent,'HOT',str(hot)),patch.object(agent,'PORTAL_MODE',True),patch.object(agent,'ACTIVE_OPERATION',{'token':'test'}),patch.object(agent,'status') as status,patch.object(agent,'drive'),patch.object(agent,'download',side_effect=download),patch.object(agent,'scan_file'):
                agent.prepare(batch,already_claimed=True)
                self.assertEqual(status.call_args.args,(19,'PREPARED'))
                folder,files=agent.verify_prepared_batch(batch,with_hashes=True)
                self.assertEqual(files[0][0].suffix,'.tif')
                original=next((folder/'originals').rglob('*.png'))
                original_hash=agent.digest(original)
                with Image.open(files[0][0]) as output:self.assertEqual(output.size,(1000,1000))
                agent.handoff(batch)
                incoming=list(hot.iterdir());self.assertEqual(len(incoming),1)
                self.assertIn('Rave_9',incoming[0].name);self.assertIn('LBSH4019',incoming[0].name)
                self.assertEqual(agent.digest(original),original_hash)
                store=agent.output_module().RetainedJobs(workspace,hot)
                self.assertEqual(len(store.pending()),1)
                self.assertFalse(store.cleanup({'status':'SENT_TO_RIP','cleanup_authorized':False}))
                self.assertTrue(folder.exists() and incoming[0].exists())
                agent.handoff(batch)
                self.assertEqual(len(list(hot.iterdir())),1)
                proof={'id':19,'batch_id':batch['batch_id'],'payload':original_payload,'status':'PRINTED','cleanup_authorized':True,'confirmation_id':99}
                self.assertTrue(store.cleanup(proof))
                self.assertFalse(folder.exists() or incoming[0].exists())
                self.assertEqual(batch['payload'],original_payload)

    def test_sync_caches_on_pc_and_queue_reuses_exact_pinned_label_without_hot_delivery(self):
        with tempfile.TemporaryDirectory(prefix='lb-sync-') as temporary:
            root=Path(temporary);workspace=root/'workspace';workspace.mkdir();hot=root/'incoming';hot.mkdir()
            art={'side':'PRINT','material':'Rave 9','filename':'source.png','drive_file_id':'source_drive_42',
                 'md5_checksum':'a'*32,'drive_version':'7','print_label':'LBSH42-RAVE9-WHITE-STONE'}
            order={'line_item_id':'line-42','order':'#42','quantity':1,'artworks':[art]}
            batch={'id':42,'batch_id':'LB-SYNC-42','status':'CLAIMED','payload':{'batch_id':'LB-SYNC-42',
                   'print_label_policy':'BOTTOM_WHITE_V1','order_count':1,'artwork_file_count':1,'orders':[order]}}
            def download(service,file,destination):
                destination.parent.mkdir(parents=True,exist_ok=True)
                Image.new('RGB',(1000,1000),(30,60,90)).save(destination,dpi=(300,300))
            with patch.object(agent,'OUT',workspace),patch.object(agent,'HOT',str(hot)),patch.object(agent,'PORTAL_MODE',True),patch.object(agent,'ACTIVE_OPERATION',{'token':'test'}),patch.object(agent,'status'),patch.object(agent,'drive'),patch.object(agent,'download',side_effect=download) as fetch,patch.object(agent,'scan_file'),patch.object(agent,'api',return_value={'orders':[order],'next_after':0}) as api:
                agent.sync_cache_once()
                self.assertEqual(fetch.call_count,1)
                self.assertEqual(list(hot.iterdir()),[])
                self.assertEqual([call.args[0] for call in api.call_args_list],['GET'])
                agent.sync_cache_once()
                self.assertEqual(fetch.call_count,1)
                agent.prepare(batch,already_claimed=True)
                self.assertEqual(fetch.call_count,1)
                self.assertEqual(list(hot.iterdir()),[])
                file=agent.labelled_specs(batch)[0]
                cached=agent.cached_artwork(file)
                self.assertIsNotNone(cached)
                changed={**file,'print_label':'LBSH42-RAVE9-GREY-STONE'}
                self.assertIsNone(agent.cached_artwork(changed))
                changed={**file,'expected_version':'8'}
                self.assertIsNone(agent.cached_artwork(changed))
                cached[1].write_bytes(b'tampered')
                with self.assertRaises(ValueError):agent.cached_artwork(file)
