<x-layouts.app heading="Ready for Print" subheading="Approved stock orders only - exact Drive artwork safety gate">
    <div class="stats compact">
        <div class="stat"><span>Approved Orders</span><b>{{ $summary['approved_orders'] }}</b></div>
        <div class="stat"><span>Available Sets</span><b>{{ $summary['total_sets'] }}</b></div>
        <div class="stat"><span>Printable Sides</span><b>{{ $summary['printable_sides'] }}</b></div>
        <div class="stat"><span>Artwork Files</span><b>{{ $summary['artwork_files'] }}</b></div>
    </div>

    <section class="panel material queue-hero">
        <div class="panel-head">
            <div>
                <h2>Selectable Production Queue</h2>
                <p>Choose individual line items and quantities before creating a batch.</p>
            </div>
            @if($items->count())
                <button class="primary" type="submit" form="queue-batch-form">Review Selected Batch</button>
            @endif
        </div>

        <h3>Printable</h3>
        <div class="pills">
            @forelse($summary['printable_materials'] as $material => $value)
                <span><b>{{ $material }}</b> - {{ $value['sides'] }} sides - {{ number_format($value['yards'], 3) }} yd</span>
            @empty
                <span>No approved material yet</span>
            @endforelse
        </div>
    </section>

    <form id="queue-batch-form" method="post" action="{{ route('batch.preview') }}">
        @csrf

        <div class="cards">
            @forelse($items as $item)
                <article class="job-card selectable">
                    <div class="job-title">
                        <label class="select-line">
                            <input type="checkbox" data-select-item="{{ $item->id }}">
                            <span>
                                <small>{{ $item->order_number }}</small>
                                <b>{{ $item->product }}</b>
                            </span>
                        </label>
                        <strong class="badge">{{ strtoupper($item->design_type) }}</strong>
                    </div>

                    <div class="meta">
                        <div><span>Variant</span><b>{{ $item->variant ?: '-' }}</b></div>
                        <div><span>SKU</span><b>{{ $item->sku ?: '-' }}</b></div>
                        <div><span>Series</span><b>{{ $item->series_code }}</b></div>
                        <div><span>Outstanding</span><b>{{ $item->quantity }}</b></div>
                        <div><span>Available</span><b>{{ $item->available_quantity }}</b></div>
                        <div>
                            <span>Batch Qty</span>
                            <input class="qty-input" name="items[{{ $item->id }}]" data-qty-item="{{ $item->id }}" type="number" min="0" max="{{ $item->available_quantity }}" value="0">
                        </div>
                    </div>

                    <div class="drive-grid {{ $item->design_type === 'single' ? 'one' : '' }}">
                        @foreach($item->matches as $match)
                            <div class="art">
                                <label>{{ strtoupper($match['side'] ?? 'PRINT') }} - {{ $materials[$item->series_code][($match['side'] ?? 'single') === 'back' ? 1 : 0] ?? 'Material' }}</label>
                                <img src="https://drive.google.com/thumbnail?id={{ $match['id'] }}&sz=w800" alt="">
                                <p>{{ $match['name'] }}</p>
                                <a target="_blank" href="{{ $match['webViewLink'] }}">Open approved artwork</a>
                            </div>
                        @endforeach
                    </div>

                    <div class="job-actions">
                        <span class="ok">APPROVED</span>
                        <span class="inline-feedback" data-queue-feedback role="status" aria-live="polite"></span>
                    </div>
                </article>
            @empty
                <div class="empty compact-empty">No approved stock orders are ready for print.</div>
            @endforelse
        </div>

        @if($items->count())
            <div class="sticky-actions">
                <button class="primary" type="submit">Review Batch</button>
            </div>
        @endif
    </form>
</x-layouts.app>
