<x-layouts.app heading="Production Dashboard" subheading="Orders, artwork readiness and factory print status">
    <div class="stats">
        <div class="stat"><span>Production Items</span><b>{{ $total }}</b><em>Current Shopify candidates</em></div>
        <div class="stat green"><span>Ready for Print</span><b>{{ $ready }}</b><em>Approved with open quantity</em></div>
        <div class="stat amber"><span>Needs Review</span><b>{{ $review }}</b><em>Artwork match review</em></div>
        <div class="stat purple"><span>Custom Artwork</span><b>{{ $custom }}</b><em>Separate approval journey</em></div>
    </div>

    <div class="grid2">
        <section class="panel sync-panel">
            <div class="panel-head">
                <div>
                    <h2>Shopify Manual Sync</h2>
                    <p>Read-only backlog import. Fulfillment is never modified.</p>
                </div>
                <a class="btn secondary" href="/ready-for-print">Open Queue</a>
            </div>

            <form class="sync-controls" method="post" action="{{ route('production.sync-backlog') }}" onsubmit="return confirm('Read Shopify backlog and match artwork from Google Drive? This does not change Shopify fulfillment.')">
                @csrf
                <label class="radio-card">
                    <input type="radio" name="mode" value="unfulfilled" checked>
                    <span><b>Unfulfilled Only</b><em>Default production backlog</em></span>
                </label>
                <label class="radio-card">
                    <input type="radio" name="mode" value="unfulfilled_partial">
                    <span><b>Unfulfilled + Partially Fulfilled</b><em>Includes partially fulfilled orders</em></span>
                </label>
                <button class="primary sync-button" type="submit">Sync Backlog</button>
            </form>

            @if(session('error_details'))
                <details class="sync-error" open>
                    <summary>{{ session('error') ?: 'Shopify sync failed.' }}</summary>
                    <pre>{{ session('error_details') }}</pre>
                </details>
            @endif

            @if($lastSync)
                <div class="sync-meta">
                    <b>Last sync:</b> {{ $lastSync->created_at->format('M d, Y g:i A') }}
                    <span>Mode: {{ $lastSync->mode }}</span>
                    @if($lastSync->error)
                        <details class="sync-error">
                            <summary>Last sync failed</summary>
                            <pre>{{ $lastSync->error }}</pre>
                        </details>
                    @else
                        <span>{{ $lastSync->shopify_items }} items, {{ $lastSync->orders_examined }} orders, {{ $lastSync->quantity_deltas }} quantity deltas</span>
                    @endif
                </div>
            @endif

            <div class="summary-row">
                <div><span>Approved orders</span><b>{{ $summary['approved_orders'] }}</b></div>
                <div><span>Total sets</span><b>{{ $summary['total_sets'] }}</b></div>
                <div><span>Printable sides</span><b>{{ $summary['printable_sides'] }}</b></div>
                <div><span>Artwork files</span><b>{{ $summary['artwork_files'] }}</b></div>
            </div>

            <h3>Printable material</h3>
            <div class="pills">
                @forelse($summary['printable_materials'] as $material => $value)
                    <span>{{ $material }} - {{ $value['sides'] }} sides - {{ number_format($value['yards'], 3) }} yd</span>
                @empty
                    <span>No approved material yet</span>
                @endforelse
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Recent Batches</h2>
                    <p>Factory handoff status</p>
                </div>
                <a href="/batches">View all -></a>
            </div>

            @forelse($batches as $batch)
                <a class="batch-row" href="/batches/{{ $batch->id }}">
                    <div>
                        <b>{{ $batch->batch_id }}</b>
                        <span>{{ $batch->order_count }} orders - {{ $batch->artwork_file_count }} files</span>
                    </div>
                    <strong class="badge">{{ $batch->status }}</strong>
                </a>
            @empty
                <div class="empty compact-empty">No print batches yet.</div>
            @endforelse
        </section>
    </div>
</x-layouts.app>
