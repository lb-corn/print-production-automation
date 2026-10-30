# Lucky Bags Factory Agent guide

Release 1.2.0 | Reviewed 30 October 2026 | Windows x64 and Python 3.13

## Installation decision

Use this release for a controlled factory pilot, not unattended end-to-end printing. The code was reviewed and tested locally. The review did not inspect malware on the client PC, perform an external penetration test, run a real Defender scan, or operate the physical printer. No software can be certified malware-free by this review.

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

5. Edit .env, then place the read-only Google service-account JSON at the configured path. Do not share either file. Never include them in Git, release archives or email.
6. Run .\protect-agent.ps1 as the operator who will run the agent. It restricts the installation to that operator, SYSTEM and Administrators. If it fails, stop and have IT provision equivalent permissions; do not grant Everyone or Users write access. Protect any external hot folder and external credential location separately. This does not protect against malware already running as that same operator or Administrator.

## Required configuration

| Setting | Required value or meaning |
| --- | --- |
| PRODUCTION_API | Final HTTPS URL ending in /api. No redirects, username, query or fragment. |
| FACTORY_API_KEY | Unique random secret matching the hosted server; at least 32 characters. |
| GOOGLE_SERVICE_ACCOUNT_FILE | Absolute local path to the dedicated read-only credential JSON. |
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

The review and limitations are in SECURITY_REVIEW.md. Thirteen Python regression tests cover path, format, hash, redirect, duplicate publication, scan and quantity gates. PHP regression tests cover the application lifecycle, material math, signed confirmations, API authentication and state transitions. PowerShell scripts were parsed but not installed on the client PC. The 23 pinned dependency versions returned zero advisory matches from OSV on 30 October 2026. That check does not detect every vulnerability or malicious package and must be repeated for future releases.

Install only after the factory owner or IT accepts the remaining Print PC checks. This release supports controlled preparation and human-authorized single-set handoff, not a claim of fully automatic, quantity-aware, malware-proof printing.
