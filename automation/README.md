# Lucky Bags Factory Agent

Factory-PC component for Lucky Bags Production Management.

## Behavior
- Polls the protected Laravel Factory API for `QUEUED` or `HANDOFF_REQUESTED` batches.
- Claims one queued batch at a time through the API; no local-only claim state is trusted.
- Downloads approved Google Drive artwork originals into `FACTORY_PRINT_DIR`.
- Writes a local `batch.json` manifest with expected and downloaded artwork counts.
- Reports `CLAIMED`, `DOWNLOADING`, `PREPARED`, `SENT_TO_RIP`, or `FAILED`.
- Copies prepared artwork to `EDGE_PRINT_HOT_FOLDER` only after the operator requests handoff.
- Uses checksum/idempotency checks before overwriting hot-folder files.
- Never reports physical printing complete. Laravel requires operator confirmation after `SENT_TO_RIP`.
- Never changes Epson Edge Print presets and never enables Auto Print.

## Install
1. Install Python 3.11+ and enable "Add Python to PATH".
2. Extract this folder to `C:\LuckyBagsFactory`.
3. Open PowerShell as Administrator in that folder.
4. Run `.\install.ps1`.
5. Edit `.env`.
6. Put the Google service-account JSON at `GOOGLE_SERVICE_ACCOUNT_FILE`.
7. Confirm `PRODUCTION_API` is reachable from this Factory PC. Do not use `localhost` unless Laravel runs on this same PC.
8. Confirm `EDGE_PRINT_HOT_FOLDER` is the real Edge Print watched hot folder, then set `EDGE_PRINT_HOT_FOLDER_CONFIRMED=yes`.
9. Run `.\preflight.ps1`.
10. Run a controlled `.\run-once.ps1` test only after preflight passes.
11. After acceptance, run `.\install-autostart.ps1`.

## Preflight
`.\preflight.ps1` checks Python, dependencies, config, API auth/connectivity, Google Drive readonly access, writable local directories, version/integrity gates, and hot-folder configuration. It does not claim batches or submit print jobs.

Directory existence is not proof that Edge Print watches the folder. Verify the watched hot folder manually in Epson Edge Print.

## Startup Task
`install-autostart.ps1` runs preflight before registering `LuckyBagsFactoryAgent`. Verify the task account can read `.env`, service-account JSON, write `FACTORY_PRINT_DIR`, and write the Edge Print hot folder.

## Update
Create a release ZIP with:

```powershell
.\make-release.ps1
```

Install a verified ZIP with:

```powershell
.\update-agent.ps1 -ZipPath <zip> -ExpectedSha256 <sha256>
```

Machine-specific `.env`, credentials, logs, venv, and temporary artwork are preserved.

## Rollback
1. Run `.\uninstall-autostart.ps1`.
2. Restore the previous release folder or rerun `update-agent.ps1` with the previous verified ZIP/SHA.
3. Run `.\preflight.ps1`.
4. Reinstall startup only after preflight passes.

## Security
Never commit `.env`, `service-account.json`, `.venv`, logs, or `factory_print`.
