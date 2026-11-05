import hashlib,json,os,re,subprocess,sys,tempfile,time,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
@unittest.skipUnless(os.name=='nt','Windows maintenance guard')
class UpdateMaintenanceTests(unittest.TestCase):
 def fixture(self,root):
  (root/'workspace').mkdir()
  code="from pathlib import Path\nimport msvcrt,json\nOUT=Path(__file__).parent/'workspace'\ndef stable_agent_id():return 'ea1ec5ee-6313-431d-9f60-8c7834a601be'\ndef acquire_lock(name):\n h=open(OUT/name,'a+b');h.write(b'0');h.flush();h.seek(0);msvcrt.locking(h.fileno(),msvcrt.LK_NBLCK,1);return h\ndef api(method,path):\n assert method=='POST' and path=='/factory/maintenance-check'\n config=OUT/'proof.json'\n proof=json.loads(config.read_text()) if config.exists() else {'ok':True,'protocol':1,'agent_id':stable_agent_id(),'busy':False}\n if 'error' in proof:raise RuntimeError(proof['error'])\n return proof\n"
  (root/'factory_agent.py').write_text(code,encoding='utf-8');(root/'release.json').write_text(json.dumps({'factory_agent_sha256':hashlib.sha256((root/'factory_agent.py').read_bytes()).hexdigest()}))
  text=(ROOT/'update-installed-agent.ps1').read_text(encoding='utf-8');match=re.search(r"(?ms)^    \$Maintenance=@'\n(.*?)\n'@",text)
  self.assertIsNotNone(match);helper=root/'maintenance.py';helper.write_text(match[1],encoding='utf-8');return helper
 def start(self,root,helper,pids):
  return subprocess.Popen([sys.executable,'-I',str(helper),str(root),str(root/'state.json'),str(root/'release'),json.dumps(pids),str(os.getpid())],creationflags=subprocess.CREATE_NO_WINDOW)
 def state(self,root,process):
  end=time.monotonic()+8
  while not (root/'state.json').exists() and time.monotonic()<end and process.poll() is None:time.sleep(.05)
  return json.loads((root/'state.json').read_text())
 def test_idle_listener_is_resumed_after_maintenance_release(self):
  with tempfile.TemporaryDirectory(prefix='lb-update-') as temp:
   root=Path(temp);helper=self.fixture(root);counter=root/'ticks'
   code="import sys,time\nfrom pathlib import Path\np=Path(sys.argv[1])\ni=0\nwhile True:\n i+=1;p.write_text(str(i));time.sleep(.05)"
   child=subprocess.Popen([sys._base_executable,'-c',code,str(counter)],creationflags=subprocess.CREATE_NO_WINDOW);process=None
   try:
    end=time.monotonic()+3
    while not counter.exists() and time.monotonic()<end:time.sleep(.05)
    process=self.start(root,helper,[child.pid]);self.assertTrue(self.state(root,process)['ready']);before=counter.read_text();time.sleep(.2);self.assertEqual(counter.read_text(),before, str(self.state(root,process))+' helper_exit='+str(process.poll()))
    (root/'release').touch();process.wait(timeout=5);time.sleep(.15);self.assertNotEqual(counter.read_text(),before)
   finally:
    (root/'release').touch()
    if process and process.poll() is None:process.wait(timeout=5)
    child.kill();child.wait(timeout=5)
 def test_active_worker_lock_postpones_update(self):
  import msvcrt
  with tempfile.TemporaryDirectory(prefix='lb-update-busy-') as temp:
   root=Path(temp);helper=self.fixture(root)
   with open(root/'workspace/.agent.lock','a+b') as lock:
    lock.write(b'0');lock.flush();lock.seek(0);msvcrt.locking(lock.fileno(),msvcrt.LK_NBLCK,1)
    process=self.start(root,helper,[])
    try:self.assertFalse(self.state(root,process)['ready']);process.wait(timeout=5)
    finally:lock.seek(0);msvcrt.locking(lock.fileno(),msvcrt.LK_UNLCK,1)

 def test_portal_proof_handles_stale_journals_and_distinct_failures(self):
  identity='ea1ec5ee-6313-431d-9f60-8c7834a601be'
  clean={'ok':True,'protocol':1,'agent_id':identity,'busy':False}
  cases=[(clean,None),({**clean,'busy':True},'active_job'),({**clean,'busy':True,'reason':'active_transfer'},'active_transfer'),({'error':'Factory API returned HTTP 401'},'portal_auth_failed'),({'error':'Factory API returned HTTP 404'},'portal_update_required'),({'error':'Factory API returned HTTP 429'},'portal_busy'),({'error':'Connection failed'},'portal_unreachable'),({**clean,'agent_id':'wrong'},'invalid_maintenance_response'),({**clean,'busy':'false'},'invalid_maintenance_response')]
  for proof,reason in cases:
   with self.subTest(reason=reason),tempfile.TemporaryDirectory(prefix='lb-update-proof-') as temp:
    root=Path(temp);helper=self.fixture(root)
    journal=root/'workspace/portal-operation.json';journal.write_text(json.dumps({'batch_id':9999,'state':'requesting'}))
    before=journal.read_bytes();(root/'workspace/proof.json').write_text(json.dumps(proof))
    process=self.start(root,helper,[])
    try:
     result=self.state(root,process)
     self.assertEqual(result['ready'],reason is None)
     if reason:self.assertEqual(result['reason'],reason)
     self.assertEqual(journal.read_bytes(),before)
    finally:
     (root/'release').touch();process.wait(timeout=5)
