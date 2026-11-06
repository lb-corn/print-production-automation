import importlib.util,json,tempfile,unittest,uuid
from pathlib import Path
from unittest.mock import patch
from PIL import Image

def load(name,path):
 spec=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
root=Path(__file__).resolve().parents[1]
output=load('custom_test_output',root/'job_output.py');peer=load('custom_test_peer',root/'custom_transfer.py');agent=load('custom_test_agent',root/'factory_agent.py')
class CustomTransferTests(unittest.TestCase):
 def fixture(self,root):
  identity=str(uuid.uuid4());folder=peer.asset_folder(root,identity,output);folder.mkdir(parents=True)
  source=folder/'original.png';Image.new('RGB',(1000,1000),'navy').save(source)
  metadata={'filename':'custom.png','bytes':source.stat().st_size,'sha256':output.digest(source),'material':'Rave9','print_label':'LBSH4269-RAVE9-NA-CUSTOM-PRO-SNIPERS','print_label_policy':'BOTTOM_WHITE_V2'}
  receipt=peer.finalize_upload(folder,metadata,identity,output,lambda p:None)
  return identity,folder,metadata,receipt
 def test_custom_source_labels_and_printed_only_owned_cleanup(self):
  with tempfile.TemporaryDirectory() as temp:
   root=Path(temp);identity,folder,meta,receipt=self.fixture(root)
   source,derived,data=peer.source_files(root,identity,meta['sha256'],output)
   self.assertEqual(receipt['source_sha256'],output.digest(source));self.assertEqual(receipt['width'],1000)
   self.assertFalse(peer.cleanup_asset(root,{'cleanup_authorized':False},output))
   proof={'id':identity,'cleanup_authorized':True,'reason':'PRINTED','source_sha256':meta['sha256']}
   extra=folder/'unowned.png';extra.write_bytes(b'other')
   with self.assertRaises(ValueError):peer.cleanup_asset(root,proof,output)
   extra.unlink();self.assertTrue(peer.cleanup_asset(root,proof,output));self.assertFalse(folder.exists())
 def test_wrong_hash_or_changed_source_blocks_reuse(self):
  with tempfile.TemporaryDirectory() as temp:
   root=Path(temp);identity,folder,meta,_=self.fixture(root)
   with self.assertRaises(ValueError):peer.source_files(root,identity,'f'*64,output)
   (folder/'original.png').write_bytes(b'changed')
   with self.assertRaises(ValueError):peer.source_files(root,identity,meta['sha256'],output)
 def test_custom_prepare_reuses_pc_original_without_google_drive(self):
  with tempfile.TemporaryDirectory() as temp:
   root=Path(temp);identity,folder,meta,_=self.fixture(root);pc='a448d851-4aba-42a0-9ba9-f3916c803975'
   batch={'id':42,'batch_id':'LB-CUSTOM-42','status':'CLAIMED','payload':{'batch_id':'LB-CUSTOM-42','print_label_policy':'BOTTOM_WHITE_V2','order_count':1,'artwork_file_count':1,'orders':[{'line_item_id':'custom-42','order':'4269','quantity':1,'design_type':'double','artworks':[{'source_kind':'pc_custom','custom_upload_id':identity,'source_sha256':meta['sha256'],'factory_agent_id':pc,'side':'FRONT','material':'Rave9','filename':'custom.png','print_label':meta['print_label']}]}]}}
   with patch.object(agent,'OUT',root),patch.object(agent,'stable_agent_id',return_value=pc),patch.object(agent,'PORTAL_MODE',True),patch.object(agent,'ACTIVE_OPERATION',{'token':'test'}),patch.object(agent,'status'),patch.object(agent,'scan_file'),patch.object(agent,'custom_module',return_value=peer),patch.object(agent,'drive') as drive:
    agent.prepare(batch,already_claimed=True);drive.assert_not_called();_,files=agent.verify_prepared_batch(batch,with_hashes=True);self.assertEqual(len(files),1);self.assertTrue(folder.exists())
