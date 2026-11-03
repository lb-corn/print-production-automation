# Factory Agent installation, monitoring and safe connection test

The website receives outbound authenticated reports from the Print PC. It does not connect into that PC. Health checks and heartbeat handlers cannot claim, download, hand off or print batches. Keep the existing HANDOFF and PRINTED confirmations and Epson Auto Print OFF.

## Server activation

Deploy main through the normal workflow. In cPanel Terminal, from `/home2/antspixa/public_html/x_dev/luckyprodauto/prod`, run:

```bash
bash deployment/post-deploy.sh
```

This applies the additive `2026_11_03_000006_create_factory_agents` migration. Preserve all credentials, batches, journals and production data. If applying only this migration manually, use the existing PHP 8.3 binary:

```bash
/opt/cpanel/ea-php83/root/usr/bin/php artisan migrate --force --path=database/migrations/2026_11_03_000006_create_factory_agents.php
/opt/cpanel/ea-php83/root/usr/bin/php artisan optimize:clear
```

`FACTORY_API_KEY` must match the PC key, contain at least 32 characters and not be a placeholder. Config-cache refresh is required after editing the server .env. Optional server setting `FACTORY_AGENT_OFFLINE_SECONDS=120` controls expiry (bounded to 30–600 seconds).

## Connection-only reporting without upgrading the existing agent

Installed version and SHA are read from the PC's own VERSION and factory_agent.py files. The admin tab is a compact connection summary; it does not assume an installed version. The test-connectivity.ps1 helper only tests authentication and does not keep the portal connected.

Download report-connection.ps1 from Admin Settings → Factory Agent and copy it beside the existing agent scripts. Run in ordinary PowerShell:

```powershell
cd C:\LuckyBagsFactory
.\report-connection.ps1
```

Keep that window open. It posts connection reports every 30 seconds without reading/claiming batches, downloading artwork, calling Google Drive or publishing anything to Epson. Reports are labeled Connection monitor only, not an active production worker. Ctrl+C stops reports; no startup task is installed. Optional -Once sends one report, which naturally expires. Existing .env and credentials stay in place. The reporter is bundled in new releases and can be copied by itself; upgrading the main agent is not required for connection status. If Windows blocks the script, have IT approve the reviewed file rather than bypassing execution policy.

## Existing installation: safe key test

Your v1.2.2 SHA256 `828f4e58f4139583be879021389db95bd92a7297b411e5f813e51d69a3928b6f` identifies the previous reviewed source, not the new monitoring source.

1. Open **Admin Settings → Factory Agent → Installation & connection test** after deploying the portal update.
2. Download `test-connectivity.ps1` from that authenticated page and place it in `C:\LuckyBagsFactory`.
3. In ordinary PowerShell:

```powershell
cd C:\LuckyBagsFactory
.\test-connectivity.ps1
```

It reads the key directly from the local .env and calls **GET `/api/factory/health`**, with TLS verification, no redirects and bounded timeouts. It prints only safe status messages. It never reads the batch queue. Expected: HTTP 200, API key authentication PASSED, monitoring database ready True. `/api/health` is not the endpoint. Missing bearer authentication returns 401; that does not prove a configured key is wrong.

404 means the portal route has not been deployed or the URL is wrong. 401 means the bearer key is missing/different. 503 indicates insecure server key configuration or monitoring setup not ready. 429 means wait for the diagnostic rate limit. Network errors require DNS/TLS/firewall checks; never disable certificate validation. Do not paste the key or .env into support messages.

## Upgrade for PC telemetry

v1.2.2 continues using the unchanged batch API but has no heartbeat feature. **Install the updated worker for integrated heartbeats and local diagnostics, or use the connection-only reporter above without upgrading.** The health helper alone does not create a heartbeat.

Stop the scheduled agent and all agent processes before updating. Build and verify the public update on the development PC:

```powershell
cd D:\LuckyBags\Automation\print-production-automation
.\automation\make-release.ps1 -PublicRelease
```

The public ZIP contains 19 approved code/documentation files and no .env, credentials, agent-id, workspace or logs. Transfer the ZIP and its paired checksum through a trusted channel. Compare `Get-FileHash -Algorithm SHA256` against that checksum; scan the ZIP locally. ZIP rebuilds change hashes.

**v1.2.2 updater compatibility:** its old allowlist rejects the new `test-connectivity.ps1` entry. After verifying the public ZIP, extract **only** its reviewed `update-agent.ps1` into the existing installation first (with the agent stopped), then use that updated script:

```powershell
cd C:\LuckyBagsFactory
.\update-agent.ps1 -ZipPath "C:\Downloads\LuckyBags-Factory-Agent-v1.3.2.zip" -ExpectedSha256 "PASTE_THE_TRUSTED_64_CHARACTER_ZIP_SHA256"
.\install.ps1 -PythonExe "C:\Actual\Path\To\Python313\python.exe"
.\protect-agent.ps1
.\run-once.ps1 -CheckOnly
.\run-once.ps1 -Health
.\run-once.ps1 -Diagnose
```

Replace paths/checksum with actual trusted values. If optional REQUIRED_AGENT_VERSION/SHA256 gates are set in .env, update them to the verified new release. Existing .env, credential JSON, batch workspaces and handoff journals stay in place. Do not delete or reset them to troubleshoot. Keep the same configured workspace. Do not extract a PrivateInstall ZIP over an existing installation.

`-Diagnose` does not start the batch loop: it checks read-only Drive connectivity, folder access with temporary empty directories, and Defender with a benign temporary file in the local workspace. Nothing is downloaded or published to Epson. A scan diagnostic proves execution of the scanner, not safety of future artwork. It sends one authenticated diagnostic heartbeat, so the page shows its source version/hash. The generated `agent-id` persists beside the scripts and is not bundled in releases. The process status is Checking; it does not mean automatic processing is running.

The -Health and -Diagnose wrapper switches require v1.3.0. **Do not pass new switches to the old v1.2.2 run-once.ps1**, which does not support them and can fall back to normal batch processing. Use test-connectivity.ps1 until the complete update is installed. The new wrapper and Python entry point reject unknown/combined modes rather than falling through to the worker.

Only after the factory pilot is approved should the operator start the usual worker. The new code does not enable autostart or Auto Print. A running worker sends heartbeats independently every 30 seconds, including during long downloads/scans; failures do not retry batch actions. Offline is shown after 120 seconds by default. A one-time diagnostic naturally becomes Offline unless a worker keeps reporting.

## What monitoring proves

The admin tab shows self-reported installed version/SHA256, Python/Windows versions, server receipt time, existence of credential/workspace/hot folders, explicit diagnostic results, current database batch ID/stage, and bounded recent activity. Installed version and SHA are presented directly from each PC report. This is not independent remote attestation of a compromised PC.

All Epson registration, application-running, Auto Print and printer-readiness fields remain **Unknown — operator verification required**. Directory existence/write access does not prove these settings. Google read-only API connectivity does not prove access to every approved artwork folder. Do not mark them ready based on heartbeat alone.

The shared app-header badge exposes only connection state/count. Detailed reports and the helper download are admin-only. No Windows paths, API keys, private keys, access tokens, customer/order names or artwork bytes are collected. Telemetry rejects unknown fields and unbounded strings, stale/future timestamps and duplicate/replayed samples. Server receipt time determines freshness. Diagnostics older than 10 minutes or from an offline PC are marked historical; failed dashboard refreshes never keep a healthy indicator current. Activity retains the last 20 changes per agent; registration is capped at 20 identifiers. Run only one processing PC against this queue.

Health is authenticated/rate-limited and read-only; heartbeat is authenticated/rate-limited and has its own monitoring writes. Neither uses the production PortalWriteLock or a batch state transition. There is no remote shell, remote updater, printer-setting change or arbitrary deletion endpoint.

## HTTP 406 from hosting

A 406 is not one of the portal heartbeat validation responses. Deploy the latest portal update, then replace report-connection.ps1 on the PC and test with .\report-connection.ps1 -Once. The updated reporter uses ordinary form-encoded telemetry and a branded client identifier while keeping verified HTTPS and bearer authentication. The portal accepts both JSON and form clients under the same security rules. If 406 persists, ask hosting support to inspect the ModSecurity/WAF audit entry for POST /api/factory/heartbeat at the UTC time printed by the reporter and review the specific false-positive rule. Do not disable ModSecurity globally or paste bearer keys into support messages. A host-side policy rejection cannot be confirmed or repaired from a screenshot alone.
