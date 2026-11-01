<section class="panel admin-form">
        <h2>Start production fresh</h2><p>Clears synced orders, artwork approvals, print queues and print history. Keeps artwork mappings, naming rules, connection settings, users and migration records. Shopify products and Google Drive files stay unchanged.</p>
        <dl class="reset-counts">@foreach(['production_items' => 'Order items', 'verification_decisions' => 'Artwork decisions', 'print_batches' => 'Print batches', 'batch_confirmations' => 'Print confirmations'] as $table => $label)<div><dt>{{ $label }}</dt><dd>{{ number_format($counts[$table]) }}</dd></div>@endforeach</dl>
        <div class="reset-warning">Deleted records cannot be restored from this screen. Make a database backup first. Stop the factory agent and complete or cancel physical print jobs before resetting. Previously processed orders may become eligible for printing again after a fresh import.</div>
        <form method="post" action="{{ route('admin.reset.store') }}">
            @csrf
            <label class="admin-check"><input type="checkbox" name="agents_paused" value="1" required> I have paused the factory agent and stopped outstanding print jobs.</label>
            <label for="reset-password">Your admin password</label><input id="reset-password" type="password" name="password" autocomplete="current-password" required>
            <label for="reset-confirmation">Type RESET PORTAL to confirm</label><input id="reset-confirmation" name="confirmation" autocomplete="off" required pattern="RESET PORTAL" placeholder="RESET PORTAL">
            <div class="admin-actions"><a href="{{ route('dashboard') }}">Cancel</a><button class="danger-button" type="submit">Reset production data</button></div>
        </form>
        <p class="admin-hint">After reset, open Overview and pull the Shopify backlog to continue. A reset does not trigger an import automatically.</p>
    </section>
