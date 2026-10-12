# Lucky Bags Factory Handover

## Prerequisites
- Windows Factory PC with Python 3.11+ in PATH.
- Network access from the Factory PC to `PRODUCTION_API`.
- Valid `FACTORY_API_KEY` matching Laravel `.env`.
- Google service-account JSON with readonly access to approved artwork folders.
- Existing Epson Edge Print setup. Do not change presets, printer device, or Auto Print.
- Confirm configured Edge Print device remains `SC-F9400H/USB003` and physical printer remains Epson SureColor `F9470H`.

## Install
1. Extract the agent to `C:\LuckyBagsFactory`.
2. Open PowerShell as Administrator.
3. Run:

```powershell
cd C:\LuckyBagsFactory
.\install.ps1
```

4. Edit `.env`:

```text
PRODUCTION_API=https://<host>/api
FACTORY_API_KEY=<secret>
GOOGLE_SERVICE_ACCOUNT_FILE=C:\LuckyBagsFactory\service-account.json
FACTORY_PRINT_DIR=C:\LuckyBagsFactory\factory_print
EDGE_PRINT_HOT_FOLDER=<actual Edge Print watched folder>
EDGE_PRINT_HOT_FOLDER_CONFIRMED=yes
```

Do not use `localhost` unless Laravel runs on the Factory PC.

## Preflight
Run:

```powershell
.\preflight.ps1
```

Preflight checks Python, dependencies, required config, API auth/connectivity, Drive readonly access, writable directories, agent version/integrity, and hot-folder configuration. It does not claim batches and does not submit print jobs.

## Controlled Test
1. In Laravel, create or select a small approved batch.
2. Let the Factory Agent run once:

```powershell
.\run-once.ps1
```

3. Confirm the batch reaches `PREPARED` and artwork exists under `FACTORY_PRINT_DIR`.
4. In Laravel, explicitly request Edge Print handoff.
5. Run:

```powershell
.\run-once.ps1
```

6. Confirm batch reaches `SENT_TO_RIP`.
7. Verify files appear in Epson Edge Print hot folder.
8. Confirm Auto Print is OFF.
9. Physically print only after operator review in Edge Print.
10. Record actual printed/failed quantities in Laravel.

## Startup
After preflight and controlled test pass:

```powershell
.\install-autostart.ps1
```

Verify the scheduled task account can read credentials and write both working and hot-folder paths.

## Troubleshooting
- `401`: verify `FACTORY_API_KEY` and Laravel `.env`.
- API timeout: verify `PRODUCTION_API` is reachable from Factory PC; do not assume localhost.
- Drive failure: verify service account file path and Drive folder sharing.
- Hot-folder warning: manually verify Edge Print watches the configured folder; directory existence is not enough.
- Batch stuck `CLAIMED` or `DOWNLOADING`: inspect `automation\logs\factory-agent.log`, then retry or mark failed from Laravel if needed.
- `SENT_TO_RIP` does not mean printed; operator confirmation is still required.

## Rollback
1. Stop startup:

```powershell
.\uninstall-autostart.ps1
```

2. Restore the previous verified release ZIP:

```powershell
.\update-agent.ps1 -ZipPath <previous.zip> -ExpectedSha256 <previous-sha>
```

3. Run `.\preflight.ps1`.
4. Reinstall startup only after preflight passes.

## Acceptance Checklist
- Preflight passes.
- API URL is remote/reachable from Factory PC.
- Drive readonly access works.
- `FACTORY_PRINT_DIR` writable.
- Edge Print hot folder manually verified as watched.
- Auto Print remains OFF.
- `QUEUED -> CLAIMED -> DOWNLOADING -> PREPARED` works.
- Explicit handoff reaches `SENT_TO_RIP`.
- No automatic physical print completion occurs.
- Operator can record actual printed/failed quantities in Laravel.
