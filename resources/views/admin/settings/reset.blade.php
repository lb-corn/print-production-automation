<section class="panel admin-form production-reset">
    <div class="reset-intro"><h2>Start production fresh</h2><p>Clear production work and reload current Shopify orders for a fresh review.</p></div>
    <dl class="reset-counts">@foreach(['production_items' => 'Order items', 'verification_decisions' => 'Artwork decisions', 'print_batches' => 'Print batches', 'batch_confirmations' => 'Confirmations'] as $table => $label)<div><dt>{{ $label }}</dt><dd>{{ number_format($counts[$table]) }}</dd></div>@endforeach</dl>
    <div class="reset-warning"><strong>This removes production history.</strong> Back up the database first. Previously processed orders may become eligible for printing again.</div>
    <section class="reset-step" aria-labelledby="reset-pc-title">
        <div class="reset-step-heading"><span aria-hidden="true">1</span><div><h3 id="reset-pc-title">Clear the Print PC</h3><p>Complete this on the Print PC before resetting the portal.</p></div></div>
        <ol class="reset-instructions"><li>Pause Epson imports. Disable the <strong>LuckyBagsFactoryAgent</strong> task and close its listener.</li><li>Open the cleanup download and type <code>CLEAR PRINT FILES</code> when prompted.</li></ol>
        <div class="reset-download"><a class="reset-download-button" href="{{ route('admin.reset.pc-cleanup') }}">Download PC cleanup <span aria-hidden="true">↓</span></a><span>Clears generated files and incoming print images.<br>Installation and settings are preserved.</span></div>
        <p class="admin-hint">Cleanup stops if the agent is still running. Keep it paused until the portal reset succeeds.</p>
    </section>
    <section class="reset-step" aria-labelledby="reset-portal-title">
        <div class="reset-step-heading"><span aria-hidden="true">2</span><div><h3 id="reset-portal-title">Reset portal and reload Shopify</h3><p>Current unfulfilled bag orders return for fresh artwork review.</p></div></div>
        <form method="post" action="{{ route('admin.reset.store') }}">
            @csrf
            <div class="reset-options"><label class="admin-check"><input type="checkbox" name="reload_shopify" value="1" checked><span><strong>Import current Shopify orders</strong><small>If import fails, existing portal records are preserved.</small></span></label>
            <label class="admin-check"><input type="checkbox" name="agents_paused" value="1" required><span>I have paused the factory agent and stopped outstanding print jobs.</span></label></div>
            <div class="reset-fields"><div><label for="reset-password">Admin password</label><input id="reset-password" type="password" name="password" autocomplete="current-password" required></div><div><label for="reset-confirmation">Type RESET PORTAL to confirm</label><input id="reset-confirmation" name="confirmation" autocomplete="off" required pattern="RESET PORTAL" placeholder="RESET PORTAL"></div></div>
            <div class="admin-actions"><a href="{{ route('dashboard') }}">Cancel</a><button class="danger-button" type="submit">Reset production data</button></div>
        </form>
    </section>
    <p class="reset-preserved">Artwork mappings, naming rules, connection settings and users are kept. Shopify and Google Drive files are unchanged. Restart the agent after a successful reset.</p>
</section>
