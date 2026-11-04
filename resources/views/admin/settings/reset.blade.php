<section class="panel admin-form">
        <h2>Start production fresh</h2><p>Clears synced orders, artwork approvals, print queues and print history. Keeps artwork mappings, naming rules, connection settings, users and migration records. Shopify products and Google Drive files stay unchanged.</p>
        <dl class="reset-counts">@foreach(['production_items' => 'Order items', 'verification_decisions' => 'Artwork decisions', 'print_batches' => 'Print batches', 'batch_confirmations' => 'Print confirmations'] as $table => $label)<div><dt>{{ $label }}</dt><dd>{{ number_format($counts[$table]) }}</dd></div>@endforeach</dl>
        <div class="reset-warning">Deleted records cannot be restored from this screen. Make a database backup first. Stop the factory agent and complete or cancel physical print jobs before resetting. Previously processed orders may become eligible for printing again after a fresh import.</div>
        <div class="reset-warning"><strong>1. Clear the Print PC first</strong><p>On the Print PC, disable the LuckyBagsFactoryAgent task in Task Scheduler, close its listener and pause Epson imports. Download and open the cleanup file below. Type CLEAR PRINT FILES when prompted. It clears generated workspace files and incoming print images, preserving installation and settings. If the agent is still running, cleanup stops.</p><a class="btn" href="{{ route('admin.reset.pc-cleanup') }}">Download PC cleanup</a></div>
        <h3>2. Reset portal and reload Shopify</h3>
        <form method="post" action="{{ route('admin.reset.store') }}">
            @csrf
            <label class="admin-check"><input type="checkbox" name="reload_shopify" value="1" checked> Import current Shopify unfulfilled bag orders after clearing. If import fails, preserve the existing portal data.</label>
            <label class="admin-check"><input type="checkbox" name="agents_paused" value="1" required> I have paused the factory agent and stopped outstanding print jobs.</label>
            <label for="reset-password">Your admin password</label><input id="reset-password" type="password" name="password" autocomplete="current-password" required>
            <label for="reset-confirmation">Type RESET PORTAL to confirm</label><input id="reset-confirmation" name="confirmation" autocomplete="off" required pattern="RESET PORTAL" placeholder="RESET PORTAL">
            <div class="admin-actions"><a href="{{ route('dashboard') }}">Cancel</a><button class="danger-button" type="submit">Reset production data</button></div>
        </form>
        <p class="admin-hint">With import selected, current Shopify orders return for fresh artwork review. This clears portal data only; generated files on the Print PC require a separate cleanup. Keep the agent paused until that cleanup is complete.</p>
    </section>
