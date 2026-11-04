"""Isolated public release publishing fixtures; no deployed files are changed."""
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

spec=importlib.util.spec_from_file_location('publisher',Path(__file__).resolve().parents[1]/'automation/publish_release.py')
publisher=importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)


class ReleaseHistoryTests(unittest.TestCase):
    def package(self, root, version, source=b'example', private=False):
        path=root/('build-'+version+'.zip')
        with zipfile.ZipFile(path,'w') as archive:
            archive.writestr('VERSION',version)
            archive.writestr('factory_agent.py',source)
            archive.writestr('install-portal-control.ps1','example installer')
            archive.writestr('release.json',json.dumps(dict(version=version,factory_agent_sha256=hashlib.sha256(source).hexdigest())))
            if private:archive.writestr('.env','never publish')
        return path

    def test_retains_five_in_semantic_order_and_updates_current(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp);resources=root/'resources'
            for v in ['1.0.0','1.1.0','1.2.0','1.3.0','1.9.0','1.10.0']:
                publisher.publish(self.package(root,v),resources,'Example release')
            rows=publisher.catalog(resources)
            self.assertEqual([r['version'] for r in rows],['1.10.0','1.9.0','1.3.0','1.2.0','1.1.0'])
            self.assertEqual(len(list((resources/'factory-agent-releases').glob('*.zip'))),5)
            self.assertEqual(publisher.digest(resources/'factory-agent-install.zip'),rows[0]['sha256'])
            self.assertEqual(json.loads((resources/'factory-agent-release.json').read_text())['version'],'1.10.0')

    def test_private_or_changed_versions_are_refused(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp);resources=root/'resources'
            publisher.publish(self.package(root,'1.0.0'),resources,'Original')
            original=publisher.catalog(resources)
            with self.assertRaises(ValueError):publisher.publish(self.package(root,'1.1.0',private=True),resources,'Private')
            with self.assertRaises(ValueError):publisher.publish(self.package(root,'1.0.0',source=b'changed'),resources,'Changed')
            self.assertEqual(publisher.catalog(resources),original)


if __name__=='__main__':unittest.main()
