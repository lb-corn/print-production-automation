import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from PIL import Image

spec = importlib.util.spec_from_file_location('job_output', Path(__file__).resolve().parents[1] / 'job_output.py')
output = importlib.util.module_from_spec(spec)
spec.loader.exec_module(output)


class JobOutputTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='lb-output-')
        self.root = Path(self.temp.name)
        self.workspace = self.root / 'workspace';self.workspace.mkdir()
        self.hot = self.root / 'incoming';self.hot.mkdir()
        self.store = output.RetainedJobs(self.workspace, self.hot)

    def tearDown(self):
        self.temp.cleanup()

    def fixture(self, identity=1):
        batch = {'id':identity,'batch_id':f'LB-JOB-{identity}','payload':{'order_count':1,'orders':[{'order':'#4019'}]}}
        folder = self.workspace / batch['batch_id'];folder.mkdir()
        local = folder / 'owned.tif';local.write_bytes(b'owned production artwork')
        incoming = self.hot / (batch['batch_id'] + '_Rave9_owned.tif');incoming.write_bytes(local.read_bytes())
        self.store.register(batch,[local],[{'name':incoming.name,'sha256':output.digest(incoming),'bytes':incoming.stat().st_size}])
        proof = {**batch,'status':'PRINTED','cleanup_authorized':True,'confirmation_id':1}
        return batch, folder, local, incoming, proof

    def test_label_preserves_dimensions_dpi_icc_and_pixels_outside_plate(self):
        source=self.root/'original.png';destination=self.root/'print.tif'
        Image.new('RGB',(1200,1200),(32,78,123)).save(source,dpi=(300,300),icc_profile=b'test profile bytes')
        original_bytes=source.read_bytes()
        result=output.render_label(source,destination,'LBSH4019-RAVE9-WHITE-LUCKY-FRIENDS',10000000)
        with Image.open(source) as original, Image.open(destination) as printed:
            self.assertEqual(printed.size,original.size)
            self.assertEqual(printed.mode,original.mode)
            self.assertAlmostEqual(printed.info['dpi'][0],original.info['dpi'][0],places=2)
            self.assertEqual(printed.info['icc_profile'],original.info['icc_profile'])
            x0,y0,x1,y1=result['label_bounds']
            self.assertEqual(printed.crop((0,0,1200,y0)).tobytes(),original.crop((0,0,1200,y0)).tobytes())
            self.assertEqual(printed.getpixel((x0,y0)),(255,255,255))
            colors=printed.crop((x0,y0,x1,y1)).getcolors((x1-x0)*(y1-y0))
            self.assertTrue(any(pixel==(0,0,0) for _,pixel in colors))
        self.assertEqual(source.read_bytes(),original_bytes)

    def test_cmyk_keeps_mode_and_uses_white_and_black_ink(self):
        source=self.root/'source.tif';destination=self.root/'print.tif'
        Image.new('CMYK',(500,500),(10,20,30,40)).save(source,dpi=(300,300))
        result=output.render_label(source,destination,'LBSH4019-RAVE9-NA-STONE',10000000)
        with Image.open(destination) as image:
            self.assertEqual(image.mode,'CMYK')
            x0,y0,x1,y1=result['label_bounds']
            self.assertEqual(image.getpixel((x0,y0)),(0,0,0,0))
            self.assertIn((0,0,0,255),[value for _,value in image.crop((x0,y0,x1,y1)).getcolors(100000)])

    def test_new_job_never_deletes_previous_or_unrelated_files(self):
        first=self.fixture(1);second=self.fixture(2)
        self.assertTrue(first[2].exists() and first[3].exists() and second[3].exists())
        self.assertEqual(len(self.store.pending()),2)
        self.assertFalse(self.store.cleanup({**first[4],'status':'SENT_TO_RIP','cleanup_authorized':False}))
        self.assertTrue(first[3].exists())

    def test_cleanup_requires_matching_signed_completion_and_only_removes_owner(self):
        first=self.fixture(1);second=self.fixture(2)
        unrelated=self.hot/'operator-file.tif';unrelated.write_bytes(b'keep')
        with self.assertRaises(ValueError):self.store.cleanup({**first[4],'payload':{'different':'batch'}})
        self.assertTrue(first[3].exists())
        self.assertTrue(self.store.cleanup(first[4]))
        self.assertFalse(first[1].exists() or first[3].exists())
        self.assertTrue(second[1].exists() and second[3].exists() and unrelated.exists())
        self.assertFalse(self.store.cleanup(first[4]))

    def test_consumed_hot_file_is_never_republished_and_workspace_cleans_only_when_printed(self):
        batch,folder,local,incoming,proof=self.fixture()
        incoming.unlink()
        self.assertTrue(folder.exists())
        self.assertTrue(self.store.cleanup(proof))
        self.assertFalse(folder.exists() or incoming.exists())

    def test_changed_or_unowned_files_block_cleanup(self):
        batch,folder,local,incoming,proof=self.fixture()
        incoming.write_bytes(b'changed')
        with self.assertRaises(ValueError):self.store.cleanup(proof)
        self.assertTrue(local.exists())
        incoming.write_bytes(local.read_bytes());(folder/'operator-note.txt').write_text('keep')
        with self.assertRaises(ValueError):self.store.cleanup(proof)
        self.assertTrue(incoming.exists() and (folder/'operator-note.txt').exists())

    def test_cleanup_resumes_partial_owned_deletion_after_permission_failure(self):
        batch,folder,local,incoming,proof=self.fixture()
        original_unlink=Path.unlink
        def guarded_unlink(path,*args,**kwargs):
            if path==local:raise PermissionError('File is in use')
            return original_unlink(path,*args,**kwargs)
        with patch.object(Path,'unlink',guarded_unlink):
            with self.assertRaises(PermissionError):self.store.cleanup(proof)
        self.assertFalse(incoming.exists());self.assertTrue(local.exists())
        self.assertEqual(self.store.pending()[0]['state'],'deleting')
        self.assertTrue(self.store.cleanup(proof));self.assertFalse(folder.exists())

    def test_symlink_and_cross_job_retention_paths_are_rejected(self):
        batch,folder,local,incoming,proof=self.fixture()
        jobs=self.store.load();jobs['1']['files'][0]['relative']='../outside.txt';self.store.save(jobs)
        with self.assertRaises(ValueError):self.store.cleanup(proof)
        self.assertTrue(local.exists() and incoming.exists())
