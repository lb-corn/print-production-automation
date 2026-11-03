# Portal-controlled Factory PC jobs

Deploy main and run `bash deployment/post-deploy.sh` in the existing production directory. The additive dispatch migration defaults all PCs to processing disabled. Existing orders, approvals, batches, credentials and manifests remain intact.

## One-time setup on the existing printer PC

1. In **Admin Settings → Factory Agent**, expand **One-time PC setup for portal jobs**.
2. On the PC, stop the old connection reporter with Ctrl+C and any old production worker/task. Use the same standard printer operator account; do not elevate PowerShell.
3. Save the two admin downloads into `C:\LuckyBagsFactory`: `LuckyBags-Factory-Agent.zip` and `install-portal-control.ps1`. Do not extract a PrivateInstall ZIP over an existing installation.
4. Run the exact checksum command displayed in Admin. The installer verifies the public package, updates reviewed code, preserves .env/credentials/workspace/journals, checks installed dependencies/configuration, registers a limited operator-logon task and starts the background listener. If optional required-version/source-hash pins are configured, update them deliberately to the reviewed release before repeating configuration checks. Do not bypass integrity checks.
5. Refresh Admin and choose **Enable portal jobs** for this connected listener. Enable can release previously authorized queued requests or signed handoffs; review the queue first.

No open PowerShell window is required after setup. The PC must stay on and connected, and the operator must remain signed in. The portal cannot start a process on a powered-off PC, perform Windows login or power on the printer. Configure/test the real Epson hot folder first, with Auto Print OFF. Existing connection-only reporters do not support processing controls.

## One-product test and daily use

1. Approve the actual production Drive original(s) in Artwork Verification.
2. On Ready for Print, select one quantity-one product. Choose **Queue & prepare selected** and confirm the popup. A single-side product sends one production original; a double-side product sends its approved front and back originals.
3. Open its batch. It reports waiting/offline/paused/setup-required, preparation, failure or readiness for handoff. Real bytes show the current download; checked/delivered counts show file progress. Scanning and waits have an indeterminate bar. Refresh failures hide live progress and controls rather than pretending work continued.
4. At PREPARED, review and sign HANDOFF. This authorizes the second temporary worker to validate and deliver those locked files to Epson. The worker exits after delivery. The listener remains idle for future portal requests.
5. Verify files/jobs in Epson, operate the printer and inspect actual output. Sign PRINTED only after physical completion. This app has no Epson hardware-progress or automatic physical-print confirmation integration. Quantity above one remains blocked from automatic delivery until an adapter is validated.

After the first single-side pilot succeeds, repeat with one double-side product. No production test is run by the software regression suite.

## Failure and recovery

Offline, signed-out, powered-off or stopped listener: the batch stays pending; the portal explains that processing is unconfirmed. Restore the PC connection. A stopped installed listener requires Windows/IT assistance; a website cannot reach and start a stopped process without a resident listener.

Paused PC: enable portal jobs in Admin when ready. Pause blocks new phases, while a current copy can finish safely. It does not cancel batches or stop a printer.

Interrupted claim or delivery: no automatic relaunch. Inspect the local source workspace, logs, portal operation journal, handoff journal and Epson queue before deciding recovery. Never delete a journal or reset a batch to force another copy. Existing selected-item return works only before claim and preserves remaining locked queue contents.

The portal authorizes fixed prepare/handoff operations only. It cannot execute arbitrary commands, download executable job content, change PC configuration or remotely delete files. Server-side phase tokens, atomic transitions, immutable payloads, local locks and journals prevent normal duplicate processing. Tokens are not exposed through batch-progress responses. All connections are outbound authenticated HTTPS; no inbound PC port is opened.
