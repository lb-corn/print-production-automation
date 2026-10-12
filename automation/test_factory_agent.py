import json
import sys
import tempfile
import types
import unittest
from pathlib import Path

if "requests" not in sys.modules:
    requests = types.ModuleType("requests")
    requests.request = lambda *_args, **_kwargs: None
    sys.modules["requests"] = requests

if "dotenv" not in sys.modules:
    dotenv = types.ModuleType("dotenv")
    dotenv.load_dotenv = lambda *_args, **_kwargs: None
    sys.modules["dotenv"] = dotenv

if "google.oauth2" not in sys.modules:
    google = types.ModuleType("google")
    oauth2 = types.ModuleType("google.oauth2")
    service_account = types.ModuleType("google.oauth2.service_account")
    service_account.Credentials = types.SimpleNamespace(
        from_service_account_file=lambda *_args, **_kwargs: object()
    )
    google.oauth2 = oauth2
    oauth2.service_account = service_account
    sys.modules.setdefault("google", google)
    sys.modules["google.oauth2"] = oauth2
    sys.modules["google.oauth2.service_account"] = service_account

if "googleapiclient.discovery" not in sys.modules:
    googleapiclient = types.ModuleType("googleapiclient")
    discovery = types.ModuleType("googleapiclient.discovery")
    http = types.ModuleType("googleapiclient.http")
    discovery.build = lambda *_args, **_kwargs: object()
    http.MediaIoBaseDownload = object
    sys.modules["googleapiclient"] = googleapiclient
    sys.modules["googleapiclient.discovery"] = discovery
    sys.modules["googleapiclient.http"] = http

import factory_agent


class FactoryAgentHandoffTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.out = self.root / "factory_print"
        self.hot = self.root / "hot"
        self.statuses = []
        self.failures = []

        self.original_out = factory_agent.OUT
        self.original_hot = factory_agent.HOT
        self.original_status = factory_agent.status
        self.original_status_with_reason = factory_agent.status_with_reason

        factory_agent.OUT = self.out
        factory_agent.HOT = str(self.hot)
        factory_agent.status = lambda batch_id, status: self.statuses.append((batch_id, status))
        factory_agent.status_with_reason = lambda batch_id, status, reason=None: self.failures.append((batch_id, status, reason))

    def tearDown(self):
        factory_agent.OUT = self.original_out
        factory_agent.HOT = self.original_hot
        factory_agent.status = self.original_status
        factory_agent.status_with_reason = self.original_status_with_reason
        self.tmp.cleanup()

    def test_handoff_is_idempotent_for_identical_files(self):
        batch = self.make_prepared_batch()

        factory_agent.handoff(batch)
        factory_agent.handoff(batch)

        handed_off = list(self.hot.glob("*"))
        self.assertEqual(1, len(handed_off))
        self.assertEqual(b"artwork", handed_off[0].read_bytes())
        self.assertEqual([(123, "SENT_TO_RIP"), (123, "SENT_TO_RIP")], self.statuses)
        self.assertEqual([], self.failures)

    def test_handoff_conflict_marks_failed(self):
        batch = self.make_prepared_batch()
        self.hot.mkdir(parents=True)
        (self.hot / "LB-BATCH-TEST_Rave 9_1001_SUP_PRINT_art.png").write_bytes(b"different")

        with self.assertRaises(RuntimeError):
            factory_agent.handoff(batch)

        self.assertEqual(1, len(self.failures))
        self.assertEqual((123, "FAILED"), self.failures[0][:2])
        self.assertEqual([], self.statuses)

    def make_prepared_batch(self):
        batch = {
            "id": 123,
            "batch_id": "LB-BATCH-TEST",
            "payload": {
                "batch_id": "LB-BATCH-TEST",
                "orders": [
                    {
                        "order": "#1001",
                        "series_code": "SUP",
                        "artworks": [
                            {
                                "side": "PRINT",
                                "material": "Rave 9",
                                "filename": "art.png",
                                "drive_file_id": "drive-1",
                            }
                        ],
                    }
                ],
            },
        }

        folder = self.out / "LB-BATCH-TEST"
        art = folder / "Rave 9" / "1001_SUP_PRINT_art.png"
        art.parent.mkdir(parents=True)
        art.write_bytes(b"artwork")
        (folder / "batch.json").write_text(
            json.dumps({
                "expected_artwork_files": 1,
                "downloaded_artwork_files": 1,
                "batch": batch["payload"],
            }),
            encoding="utf-8",
        )

        return batch


if __name__ == "__main__":
    unittest.main()
