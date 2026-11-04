"""Disposable local folders only; never import the installed Print PC agent."""
import importlib.util
from pathlib import Path
import tempfile
import unittest

spec=importlib.util.spec_from_file_location('cleanup',Path(__file__).resolve().parents[1]/'resources/factory-pc-cleanup.py')
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class CleanupTests(unittest.TestCase):
    def test_owned_workspaces_and_settings(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp);out=root/'factory_print';hot=root/'edgeprint/incoming'
            (out/'LB-BATCH-test').mkdir(parents=True);hot.mkdir(parents=True)
            (root/'.env').write_text('preserve');(root/'factory_agent.py').write_text('preserve')
            (out/'LB-BATCH-test/print.tif').write_bytes(b'art')
            (out/'.agent.lock').write_bytes(b'0');(out/'retained-jobs.json').write_text('{}')
            (hot/'job.tif').write_bytes(b'art');(hot/'epson.ini').write_text('preserve')
            self.assertEqual(module.cleanup(out,hot,root),3)
            self.assertTrue((root/'.env').exists());self.assertTrue((root/'factory_agent.py').exists())
            self.assertTrue((hot/'epson.ini').exists());self.assertTrue((out/'.agent.lock').exists())
            self.assertFalse((out/'LB-BATCH-test').exists())
            self.assertEqual(module.cleanup(out,hot,root),0)

    def test_unsafe_paths_and_credentials_refused_before_deletion(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp);out=root/'factory_print';out.mkdir()
            (out/'print.tif').write_bytes(b'keep');(out/'credentials.json').write_text('secret')
            with self.assertRaises(RuntimeError):module.cleanup(out,None,root)
            self.assertTrue((out/'print.tif').exists())
            with self.assertRaises(RuntimeError):module.cleanup(root,None,root)
            with self.assertRaises(RuntimeError):module.cleanup(root.parent,None,root)
            with self.assertRaises(RuntimeError):module.cleanup(out,out,root)


if __name__=='__main__':unittest.main()
