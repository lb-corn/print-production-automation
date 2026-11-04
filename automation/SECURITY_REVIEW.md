# Factory Agent security review

Review date: 3 November 2026. Current release: 1.3.2. Scope: automation source, installation/update scripts, Factory API and named batch confirmations. This is a source and local regression review, not a penetration test, malware certification, physical printer test or client-device inspection.

## 1.2.1 follow-up

- Fixed a handoff time-of-check gap: copied bytes must match the manifest hash already verified, rather than whichever source bytes exist after copying. Changes during scanning/copying fail closed; regression tested.
- New batch payloads carry available Drive MD5/version evidence, checked before download. Historical batches without these fields still use legacy live-Drive checksum checks; create fresh approved batches after deploying the web update for the added protection.
- Google HTTP calls now have an explicit 60-second transport timeout. Workspace/hot-folder paths cannot be ancestors of agent source.
- Corrected the example API host to lbprint.app.keenpillar.com and documented the exact single/two-original pilot journey and local administrator limitations.
- Seventeen Python regressions pass, including one/two-file handoff and blocked substitutions. OSV returned no advisory matches for the 23 exact locked packages on 2 November 2026. This is not a malicious-package audit or a malware-free guarantee.
- No client installation, physical printer test, real service-account access, live batch claim or print handoff was performed during this review.
- The credential-free distribution ZIP received a local Microsoft Defender custom scan; scan start/completion events were checked. This is not a client-device inspection or malware-free certification. The archive allowlist and agent manifest hash were verified separately.

## Decision

Controlled pilot only. Do not enable unattended full production. The agent prepares approved originals and can deliver a single-set batch to an existing Edge Print hot folder after recorded human confirmation. Physical print start stays with the operator. Quantity-aware multi-set RIP handoff remains unvalidated and is blocked, rather than silently sending one copy per order.

## Findings and changes

| Finding in prior code | Severity | Change and evidence |
| --- | --- | --- |
| Unset server key could match an empty bearer token | Critical | Reject missing, short and placeholder server keys before constant-time token comparison; regression tested. |
| Factory token could declare PRINTED or arbitrarily regress state | High | Server transition allowlist and row locks; physical completion exclusively requires signed-in human confirmation; regression tested. |
| Startup requested Highest and ExecutionPolicy Bypass | High | Limited-privilege logon task calling private Python directly. No elevation or policy bypass. Scripts parsed; task not installed here. |
| Download names/types were unrestricted | High | Bounded batch fields, local path containment, junction/symlink rejection, JPG/PNG/TIFF/PDF allowlist, Drive MIME/size/MD5 and signature checks. Local tests cover attacks; not a full parser sandbox. |
| Prepared validation counted files without binding contents | High | SHA256 per original and locked payload digest; unexpected files and altered content rejected; regression tested. |
| Hot-folder retries could republish consumed files | High | Publishing journal prevents automatic resubmission after publication begins; consumed-file regression test. Partial handoff requires operator recovery. |
| No malware scan gate | High | Mandatory Defender custom scan before preparation completion and handoff; scanner unavailable/nonzero status blocks action. Real Defender scanning not exercised in this review. |
| Network defaults permitted HTTP and ambient auth/proxies | High | HTTPS required outside explicit loopback development; no redirects; requests session ignores .netrc and ambient proxy settings; response size/time limits. |
| Unbounded dependency versions | High | 23 packages pinned to exact versions and wheel SHA256 values for Windows x64 Python 3.13; offline hash-locked installation tested. |
| Updater accepted all non-preserved ZIP entries | High | Explicit archive allowlist, duplicate/path/size rejection, verified ZIP/agent hashes, stopped-process gate and source backup. Integrity hash does not establish publisher identity by itself. |
| Human authorization lacked persistent identity and counts | High | Named signer, account ID/name, reviewed item/file counts, set count, payload digest and timestamp stored in additive batch_confirmations table. Separate HANDOFF and PRINTED events; tests pass. |
| Artwork copies did not apply quantity | High | Multi-set automatic handoff blocked pending a validated quantity adapter. This is an intentional remaining capability limit. |
| Cleanup and logging were weakly bounded | Medium | Automatic recursive artwork deletion removed; logs rotate; tokens/response bodies not logged; dedicated operator ACL script added. |

## Trust boundaries and remaining risks

- A compromised Windows operator account or Administrator can alter source, configuration, manifests and credentials. ACLs and optional source hashes do not make the agent tamper-proof. Use a dedicated patched account, endpoint protection and independent backups.
- Historical approval snapshots may bind only Drive file IDs. New batch payloads include available Drive MD5/version evidence and the agent checks it. Missing metadata in legacy batches remains a risk; re-review modified originals and create fresh batches. This is not an immutable archive of every approved revision.
- A valid image/PDF may exploit a vulnerable RIP/parser. Defender and file signatures reduce risk but cannot prove artwork safe. Keep Edge Print/OS/drivers patched. PDF active content is not stripped; the RIP must safely process approved originals.
- The dedicated Google account can read every Drive resource shared to it. Limit Viewer sharing to required artwork only. The factory API uses one deployment secret, not per-device enrollment, expiry or attestation.
- The installation supports one trusted agent PC. Preparation claims are atomic, but no per-device handoff lease or distributed ownership protocol is implemented. Do not run multiple agent PCs against the same deployment.
- Hot-folder publishing is atomic per file, not per whole batch. Interruptions require human inspection. The journal favors preventing duplicates over blind retries.
- Hash lock and release SHA256 checks protect repeatability/integrity only if obtained from trusted sources. These are not code-signing or a software bill of materials attestation.
- App confirmation history is ordinary database data; administrators can change it. Shared web logins weaken attribution. A typed name is a recorded acknowledgement, not a legal/digital-signature assertion.
- Local setup and browser tests do not verify client Windows ACL application, Defender availability, exact Epson presets, media geometry, actual quantity/copies, USB connectivity, or physical output.
- The installed Python 3.13.0 interpreter used for local test execution is not a recommendation to deploy that old patch level. Install current supported Python 3.13 security updates on the client.

## Dependency result

The exact 23 public package names and versions in requirements.lock were queried against https://api.osv.dev/v1/querybatch on 2 November 2026. No advisory matches were returned. See dependency-audit.json. This is a point-in-time database result, not a guarantee against unknown vulnerabilities or supply-chain compromise.

## Verification commands

```powershell
php tests/bulk-review.php
.\automation\.audit-venv\Scripts\python.exe -m unittest discover -s automation/tests -v
php artisan view:cache
```

Use a disposable test database and temporary artwork/hot directories. No live printing or actual client installation was performed. The supplied test suite mocks the Google/API/Defender boundaries where necessary and never bypasses those checks for normal runtime.

## Primary references

- pip hash-checking and binary install guidance: https://pip.pypa.io/en/stable/topics/secure-installs/
- Windows Task Scheduler privilege levels: https://learn.microsoft.com/en-us/windows/win32/taskschd/security-contexts-for-running-tasks
- Microsoft Defender command-line scanning: https://learn.microsoft.com/en-us/defender-endpoint/command-line-arguments-microsoft-defender-antivirus
- Google Drive authorization scopes: https://developers.google.com/workspace/drive/api/guides/api-specific-auth
- OSV query API: https://google.github.io/osv.dev/post-v1-querybatch/

## 1.2.2 private installation packaging

The default make-release.ps1 now intentionally includes the local .env and service-account.json in a separately named PrivateInstall ZIP. Both inputs and automation/dist remain excluded from Git. This archive contains real secrets and is not encrypted: transfer only to the trusted Print PC and keep it private. The existing web credential was copied locally at the owner's request; its Drive permissions are not reduced by copying it. Prefer replacing it with a dedicated Viewer credential before distribution. No credentials or private archive are committed. -PublicRelease retains the original credential-free update allowlist; update-agent.ps1 rejects installer secrets and never replaces installed configuration. Relative configuration paths resolve against the agent installation, not the caller's working directory. Packaging tests use fake credentials and no network, Google or printer activity. The previous Defender scan applies only to the earlier 1.2.1 credential-free ZIP, not these new archives.

## 1.3.0 monitoring review

Authenticated health/heartbeat routes are separate from batch endpoints and the production write lock. Heartbeats use a separate outbound HTTPS session with certificate verification, no ambient proxy/.netrc, no redirects, bounded reads/timeouts and no automatic batch retry. The server allowlists telemetry fields and error codes, rejects unknown/nested secret fields, caps activity and agent records, checks client timestamps and rejects non-increasing samples. Server receipt time controls Online status. Public header responses contain only connection state/count/expiry. Details require an active authenticated admin. No paths, customer text or secret values are sent or logged. Printer readiness and Epson state remain Unknown. Diagnostics only list one Drive file ID for connectivity (not transmitted), test empty owned directories, and scan a benign local file. They do not download or publish artwork. PC telemetry is self-reported, not independent attestation. New archives require their own client antivirus checks; no physical-PC/print test or external penetration test was performed. See docs/factory-agent-monitoring.md for deployment and the v1.2.2 updater compatibility step.

## 1.3.1 connection-only reporter

report-connection.ps1 reads installed version/source metadata, never batches or artwork. It sends only allowlisted telemetry over verified HTTPS and cannot start the worker, print, call Drive or change credentials. Its mode is distinguished from production-agent reports. Monitoring URLs are same-origin paths and their rate-limit buckets are isolated. The admin page omits release/version installation instructions; installed data comes solely from PC reports.

## 1.3.2 monitoring transport

Monitoring heartbeats use standard URL-encoded fields with an identifiable client User-Agent and JSON-compatible Accept header. The server still accepts existing JSON clients and applies the same bearer authentication, body-size bound, telemetry allowlist and replay checks to both representations. No WAF rules, TLS verification or key requirements are disabled. Successful response bodies are consumed only once; tests use a real streaming Response. HTTP 406 messages provide the endpoint and UTC time for hosting audit investigation without exposing secrets or raw response bodies.

## Portal dispatch review (1.4.1)

A standard-user logon listener polls outbound authenticated HTTPS and launches only the fixed local Python worker with validated numeric batch ID and UUID phase token (shell disabled). It accepts no arbitrary command, path, program or deletion instruction from the portal. New installations are disabled until an administrator enables a connected capable listener. Each phase requires atomic server ownership; delivery additionally requires signed HANDOFF and a DELIVERING barrier. Legacy workers cannot claim managed batches. Local operation and handoff journals block replay after uncertain outcomes. The listener stays resident; temporary workers exit after preparation or delivery. Pause prevents new phases and does not stop current copying or physical printing. No inbound PC port is opened.

Progress reports contain bounded counts and byte totals, with no source paths, keys or filenames. Monitoring cannot confirm physical Epson progress. Interrupted work is deliberately not auto-retried. The public admin setup ZIP has only 21 allowlisted source/documentation files and contains no .env or credentials. The one-time installer preserves secrets/workspaces and creates a Limited interactive-logon task; it requires a powered-on PC and signed-in operator. Actual Windows scheduled-task installation and an Epson print pilot still require verification on the real PC.

Background startup repair: version 1.4.1 uses the explicit Windows headless console host and a one-minute recovery trigger in addition to operator logon. Existing 1.4.0 installations can use repair-background-startup.ps1 alone without replacing credentials or agent code. Pause jobs and finish active work before repair; the repair refuses running agent processes. Actual terminal-close and sign-in tests on the client PC remain required.


### 1.5.0 print copies and retention
- Hosting receives metadata only; originals download directly from read-only Drive to the PC. The authenticated, bounded, paginated sync endpoint cannot authorize a hot-folder delivery.
- Fixed local idle cache workers hold the same OS worker lock, verify pinned checksums/revisions, scan originals/derived files and emit only metadata/heartbeats. Cache keys bind source revision, material-derived label and rendering policy. No remote command execution endpoint exists.
- Labeled TIFF derivatives preserve canvas dimensions and ICC/DPI; unsupported/multipage/rotated formats fail closed. Pinned Pillow 13.0.0 Windows CPython 3.13 x64 wheel uses hash-required binary installation; its OSV query returned no reported vulnerabilities on 2026-11-04.
- Incoming remains flat. A private ownership journal records exact hashes and paths before publication; files are never broadly cleared for a new job. Only signed PRINTED proof matching the immutable payload authorizes owned-file cleanup. Unknown, modified, linked and escaped paths block deletion. Consumed hot-folder inputs are never automatically republished.
- Old agents cannot claim new label-policy batches. Human approval and Admin handoff block still gate delivery; physical completion remains exclusively human-confirmed.


### 1.5.1 dynamic material boundaries
The portal obtains bounded CSV metadata from the fixed Google Sheets host and the Lucky Bags tab, with timeouts, TLS validation and redirects disabled. Required columns, duplicate codes, type values and nonempty materials are validated. Sync/rematch and new queue authorization require fresh valid settings before writing selections or jobs; failures preserve existing batch snapshots. Material batches are created and authorized in one database transaction. Side splitting preserves source IDs, quantities and approvals. Completion aggregates all order references; returning an untouched order locks and cancels its related sides together. V2 placement is version-gated and cache-bound; no image bytes are hosted.
