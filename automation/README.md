# Lucky Bags Factory Agent guide

Release 1.3.2 | Reviewed 3 November 2026 | Windows x64 and Python 3.13

## Printable guide and release ZIP

Use the [four-page Print PC installation and test guide](../docs/Print-PC-Installation-and-Test-Guide.pdf) for the complete development-PC packaging, client configuration, pilot and recovery checklist.

On the development PC, keep your private `automation/.env` and `automation/service-account.json` beside the scripts (both excluded from Git). If `.env` is missing, the build creates it from `config.example.env`. Set `GOOGLE_SERVICE_ACCOUNT_FILE=service-account.json`; relative credential and workspace paths resolve beside the installed agent, independent of the PowerShell working directory. Prefer a separate Viewer account; reusing the web account also exposes its broader Drive permissions if this PC or ZIP is compromised. Build your private installer:

```powershell
cd D:\LuckyBags\Automation\print-production-automation
.\automation\make-release.ps1
```

Change the project path if needed. The script creates `automation/dist/LuckyBags-Factory-Agent-v1.3.2-PrivateInstall.zip` and its matching `.sha256.txt`. This ZIP intentionally includes `.env` and `service-account.json`. Transfer it only to the trusted Print PC; ZIP compression is not encryption. Never upload it to GitHub, a public download link or the website. Logs, virtual environments and downloaded artwork remain excluded. The private bundle must remain under `automation/dist`, which Git ignores.

Transfer both generated files to the Print PC using a trusted method. Before extraction, compare the ZIP hash with the accompanying trusted checksum:

```powershell
Get-FileHash "C:\Downloads\LuckyBags-Factory-Agent-v1.3.2-PrivateInstall.zip" -Algorithm SHA256
Get-Content "C:\Downloads\LuckyBags-Factory-Agent-v1.3.2-PrivateInstall.sha256.txt"
```

Use the actual download/transfer directory. Stop if the hashes differ; letter case does not matter. Scan the ZIP with Defender, extract to `C:\LuckyBagsFactory`, and follow the guide. Rebuilding a ZIP can change its checksum, so always use the checksum generated with that exact ZIP. A matching checksum is not a publisher signature.

## Simple first-install checklist

**Queueing in the website does not immediately start a physical print.** The right-hand Drive originals are the print files: one PRINT file for a single-sided item, or both FRONT and BACK files for a double-sided item. The Shopify mockup is never the print source. Epson Edge Print receives files through its hot folder, not by this agent clicking a Windows Print dialog.

1. Have IT prepare a patched Windows PC, a dedicated standard operator account, Microsoft Defender and authentic Python 3.13 x64 with current security updates. Keep Epson Edge Print Auto Print OFF. IT may need to create C:\LuckyBagsFactory and give the operator access first.
2. Verify the release ZIP SHA256, scan it, then extract its contents directly into C:\LuckyBagsFactory. The private ZIP already includes the selected credentials and .env. Do not extract over an existing installation: that could replace its secrets.
3. Open ordinary PowerShell in that directory. Run `./install.ps1 -PythonExe "C:\Path\To\Python313\python.exe"`, replacing the interpreter path with the actual signed python.org installation. Do not bypass execution policy; ask IT to approve reviewed scripts if Windows blocks them.
4. Edit .env: use `PRODUCTION_API=https://lbprint.app.keenpillar.com/api`, a unique FACTORY_API_KEY matching the server, `GOOGLE_SERVICE_ACCOUNT_FILE=service-account.json` (already bundled), and the actual Epson pilot hot-folder path. Normally only the API key and hot-folder path need editing. Keep the supplied relative workspace path. Prefer dedicated Viewer credentials.
5. Run `./protect-agent.ps1`, then `./run-once.ps1 -CheckOnly`. A successful configuration check does not prove the network, Google account, Defender or printer works.
6. Test one disposable order with quantity one. Approve its right-hand Drive artwork in the website, create the print batch, then run `./run-once.ps1`. Confirm the batch becomes PREPARED and the manifest lists the expected one or two originals. Nothing should print at this stage.
7. In that batch's website page, review and sign HANDOFF. Run `./run-once.ps1` again. Inspect the files/jobs in Epson Edge Print with Auto Print OFF, verify scale, media, side pairing and copies, and only then start the physical print. Confirm PRINTED in the website after inspecting the actual output.

Repeat the pilot for a double-sided item before enabling `./install-autostart.ps1`. Do not run a second agent PC against the same queue. Quantities above one are prepared but automatic handoff remains blocked until a quantity/preset adapter has been validated.

Windows administrators and the account running the agent can still stop or delete its local files. The website cannot make a local folder undeletable. This release does not add remote shell commands, remote self-updates or arbitrary web-controlled deletion. The agent polls outbound HTTPS; it does not open an inbound service. Keep firewall/Defender enabled and do not expose the hot folder as a public share.

Version 1.2.1 binds copied hot-folder bytes to the verified manifest hash and rejects changes during scanning/copying. New web batches include Drive checksum/revision evidence when available, and the agent checks it before downloading. Older batch snapshots lacking those fields retain the legacy download checks; create a fresh approved pilot batch after deploying the web update for that additional protection.

## Installation decision

Use this release for a controlled factory pilot, not unattended end-to-end printing. The code was reviewed and tested locally. The previous 1.2.1 credential-free ZIP received a local Microsoft Defender custom scan; that scan does not cover the new 1.2.2 archives. This did not inspect the client PC, perform an external penetration test or operate the physical printer. No software can be certified malware-free by this review.

The intended equipment is the Epson SureColor F9470H. The operator previously confirmed the SC-F9400H queue on USB003, Epson Edge Print, hot-folder support, and Auto Print OFF. Those facts were supplied by the operator; they were not rechecked remotely during this review. Keep Auto Print OFF. This agent never connects to AnyDesk or changes Epson settings.

The supported automated path is: queued batch, download approved originals, verify and scan files, prepared batch, named human handoff confirmation, hot-folder delivery, operator review and physical printing, named human completion confirmation. Multi-set hot-folder handoff is blocked until a quantity adapter is validated. Preparation still records the correct quantities.

## Before installation

- Use a supported, patched Windows x64 PC and a dedicated standard operator account. Do not run the agent as Administrator or SYSTEM.
- Install an authentic signed Python 3.13 x64 interpreter from python.org. Install current Python 3.13 security fixes. The dependency lock is for that platform; other Python versions need a separately generated and tested lock.
- Keep Defender and its signatures current. The agent refuses delivery when the required scanner is unavailable or does not report a clean result. Do not disable antivirus or create scan exclusions for artwork.
- Update Epson Edge Print and the Epson driver through the vendor. Confirm the actual hot-folder path and presets on the Print PC.
- Use a unique strong account for every human approver. Shared logins identify the shared account, not the real person.
- Provision only the approved artwork folder to a dedicated Google service account with Viewer access. The client requests Drive read-only scope; scope alone does not restrict which shared files it can read.
- Restrict access to the installation, credentials and hot folder using Windows permissions. Protect the backup destination as well.

## Deploy the web application first

Back up the application database. Deploy the matching application and agent versions together. On the web server, run:

```powershell
php artisan migrate --force
php artisan config:cache
php artisan view:cache
```

The additive migration creates batch_confirmations. Do not use migrate:fresh and do not seed production users. Change all demo passwords. Set APP_ENV=production and APP_DEBUG=false on the hosted application. Use HTTPS with a valid certificate and correct trusted-proxy configuration. Set FACTORY_API_KEY to a unique random secret of at least 32 characters, preferably 64 random hexadecimal characters. Use the same key only on this trusted factory deployment. Never place it in a URL or a support screenshot. Restart or reload long-running app workers after deployment.

The Factory API fails closed for blank, short or placeholder keys. It restricts batch state transitions, prevents duplicate claims, and cannot mark physical printing complete. The web app requires an authenticated operator and recorded confirmation for handoff and physical completion. The audit records are application database records, not cryptographic signatures or a tamper-proof ledger.

## Install on the Print PC

1. Obtain the release ZIP and its SHA256 through an independently trusted channel. A checksum beside an untrusted ZIP does not prove publisher identity. Scan the ZIP before extraction.
2. Extract to a dedicated local directory such as C:\LuckyBagsFactory. Do not install into a shared writable folder, network path, drive root, or a junction.
3. Open an ordinary non-elevated PowerShell window as the dedicated operator. Windows policy must allow your reviewed scripts; do not globally weaken policy or use ExecutionPolicy Bypass.
4. Install dependencies with the full path to the signed interpreter:

```powershell
cd C:\LuckyBagsFactory
.\install.ps1 -PythonExe "C:\Path\To\Python313\python.exe"
```

The installer validates the signed interpreter and platform, creates a private virtual environment, installs wheels from official PyPI using the pinned SHA256 lock, and checks dependency consistency. It does not install a startup task or run print jobs. The audit environment used locally is not part of the release.

5. Edit the bundled .env: match the server API key and the actual Epson hot folder. The private installer already includes service-account.json. Public updates do not. Keep both files and the private ZIP out of Git, public downloads and email.
6. Run .\protect-agent.ps1 as the operator who will run the agent. It restricts the installation to that operator, SYSTEM and Administrators. If it fails, stop and have IT provision equivalent permissions; do not grant Everyone or Users write access. Protect any external hot folder and external credential location separately. This does not protect against malware already running as that same operator or Administrator.

## Required configuration

| Setting | Required value or meaning |
| --- | --- |
| PRODUCTION_API | Final HTTPS URL ending in /api. No redirects, username, query or fragment. |
| FACTORY_API_KEY | Unique random secret matching the hosted server; at least 32 characters. |
| GOOGLE_SERVICE_ACCOUNT_FILE | service-account.json beside the installed agent, or an absolute local path to the dedicated Viewer JSON. |
| FACTORY_PRINT_DIR | Dedicated local artwork workspace, separate from the hot folder. |
| EDGE_PRINT_HOT_FOLDER | Existing local folder watched by the confirmed Edge Print preset. |
| MAX_ARTWORK_MB | Maximum original size per artwork; default 100 MB, hard limit 500 MB. |
| REQUIRE_DEFENDER_SCAN | true. Disabling scanning blocks deployment actions. |
| ALLOW_LOCAL_HTTP | false on client PCs. true is only for an explicit loopback development test. |
| POLL_SECONDS | 30 by default; minimum 10 seconds. |
| REQUIRED_AGENT_VERSION | Optional exact release gate. Update it deliberately during upgrades. |
| REQUIRED_AGENT_SHA256 | Optional source-integrity gate. Not a replacement for Windows permissions. |

Run .\run-once.ps1 -CheckOnly. This checks configuration without API requests or printer actions; it can create the local log and lock files. It does not prove Google access, Defender scan success, Edge Print settings, or printer connectivity.

## Controlled pilot

Use a disposable test order, one set, known artwork, and a dedicated pilot hot folder. Confirm Auto Print OFF before starting. Do not use a customer's live order for the first pilot.

1. In Artwork verification, compare Shopify and Drive images, then approve the exact file IDs. Individual and bulk saves remain in place and show the result inside each card.
2. Open Ready for Print. Check item count, set count, front/back pairing, material and yardage. The configured rule is 0.143 yd per side per set, excluding extra waste; confirm that allowance with the factory before procurement.
3. Create the locked print batch. It leaves Ready for Print and becomes visible in Print batches. Repeated sync does not recreate batched or completed items.
4. Run .\run-once.ps1 to prepare the queued batch. Check the workspace manifest and the PREPARED state. Preparation downloads only allowed JPG, PNG, TIFF or PDF files, checks Drive metadata and checksum, checks file signatures, scans them, and records SHA256 hashes.
5. In the batch page, choose Review and sign handoff. Check quantities, material and the exact batch. Enter your name, tick the review statement, type HANDOFF, and accept the final confirmation. The server records your account, entered name, counts, payload digest and time.
6. Run .\run-once.ps1 again. The agent verifies the locked payload and file hashes again, scans artwork again, and publishes files to Edge Print. With Auto Print OFF, inspect the jobs before starting physical printing.
7. Verify profile, media, scale, front/back pairing, copy count, layout, ink settings and total quantity in Edge Print. The current automatic handoff allows only orders with quantity one; it does not set RIP layout, copies or presets. A quantity above one fails closed and needs a validated adapter or an explicitly managed manual workflow outside automatic handoff.
8. Physically print and inspect the full batch. Only after it finishes, choose Confirm physical print completion, enter your name, tick the review statement, type PRINTED, and accept the final confirmation.
9. Inspect the two separate records and the original artwork-approval snapshots. Print completion is a human attestation, not sensor feedback from the printer.

## Continuous operation

After the pilot passes, install .\install-autostart.ps1 from the same non-elevated operator account. It creates a limited-privilege task at operator logon, not an elevated startup service. The operator must remain signed in. The task calls the private Python interpreter directly; it does not bypass PowerShell execution policy. The local file lock prevents a second instance. Never install this deployment on a second agent PC using the same queue/key without an additional ownership design: atomic claiming protects preparation, but handoff is intended for one trusted PC.

To stop it, use Task Scheduler to stop LuckyBagsFactoryAgent. To remove autostart, run .\uninstall-autostart.ps1 after stopping the task. Keep Edge Print and the operating system patched. Keep existing approved artwork and manifests until an operator has verified the completion and archival policy. Automatic recursive retention deletion was removed; perform controlled cleanup only after inspection.

## Failures and recovery

Do not delete a manifest or handoff journal to force a retry. Do not reset a failed batch to QUEUED until an operator checks the hot folder, RIP queue and physical output. A failed or interrupted handoff may have delivered some files already. The journal intentionally blocks automatic republishing after publication starts; this avoids duplicate production when Edge Print has consumed a file. An API response failure can leave local delivery ahead of server state, so investigate both sides.

If Defender blocks a file, leave the batch failed and investigate through the normal security process. Do not whitelist or rename the file to bypass scanning. If a scan or hash fails, obtain a trusted replacement and create a new reviewed production decision through a controlled process. If a credential is exposed, revoke/rotate it and the factory key, then update the trusted server/PC configuration. Logs rotate and avoid tokens/remote response bodies; they may still identify batch IDs and must be access controlled.

## Updates and rollback

Stop the task and close agent processes first. Verify a trusted new ZIP hash, then run:

```powershell
.\update-agent.ps1 -ZipPath "C:\Downloads\release.zip" -ExpectedSha256 "TRUSTED_64_CHARACTER_SHA256"
```

The updater accepts only the explicit source allowlist, rejects unexpected archive paths, verifies the agent hash, preserves .env, credentials, artwork and logs, and creates a source-only backup. It leaves the agent stopped. Re-run install.ps1 for the new lock, reapply permissions if needed, run configuration checks, and repeat the controlled pilot before enabling the task. Source copying is not an all-files atomic deployment; if interrupted, keep the agent stopped and restore a complete source backup, including its lock, before reinstalling dependencies. Database rollback must follow the app administrator's backup plan; do not erase confirmation history to roll back source code.

## Security review evidence

The review and limitations are in SECURITY_REVIEW.md. Twenty-eight Python tests cover monitoring, read-only diagnostics, portable installation paths, paths, formats, hashes, redirects, duplicate publication, scan/copy substitution, approved Drive revision checks, one/two originals and quantity gates. PHP tests cover lifecycle, material math, named confirmations, API authentication and state transitions. PowerShell scripts were parsed but not installed on the client PC. The 23 pinned dependency versions returned zero advisory matches from OSV on 2 November 2026. That check does not detect every vulnerability or malicious package and must be repeated for future releases.

Install only after the factory owner or IT accepts the remaining Print PC checks. This release supports controlled preparation and human-authorized single-set handoff, not a claim of fully automatic, quantity-aware, malware-proof printing.

## Public updates (no credentials)

Build with ".\automation\make-release.ps1 -PublicRelease" for a distributable update ZIP. This produces LuckyBags-Factory-Agent-v1.3.2.zip with 19 approved code/documentation files, excluding .env and service-account.json. Use only this public ZIP with update-agent.ps1; the updater rejects private installer archives and preserves the installed secrets. PrivateInstall ZIPs are for fresh installations only.

Packaging regression check: .\automation\tests\test-release.ps1 (uses fake credentials, never contacts Google or a printer).

## Monitoring and read-only connection test

See [Factory Agent monitoring and upgrade instructions](../docs/factory-agent-monitoring.md). Version 1.3.0 adds outbound heartbeats and explicit diagnostics. Existing v1.2.2 batch processing remains compatible; the standalone test-connectivity.ps1 helper also works with v1.2.2. The old updater must be replaced with the reviewed updater from the verified public ZIP before accepting its new health-helper entry. No credentials, autostart tasks, Epson settings or batch confirmation rules are changed. Use run-once.ps1 -Health for read-only key verification and -Diagnose for a safe local diagnostic report; neither claims a batch. Header status is connection-only; PC details stay in Admin Settings > Factory Agent.

Connection-only status for an existing PC: copy report-connection.ps1 beside the existing agent scripts and run it. It reads the installed VERSION/source hash and sends heartbeats only; it never calls the batch API. Keep its window open. No agent upgrade or autostart is required. The admin tab labels this mode explicitly.
