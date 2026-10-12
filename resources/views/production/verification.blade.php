<x-layouts.app heading="Production Verification" subheading="Compare Shopify order artwork against factory Drive artwork">
    <div class="toolbar" role="toolbar" aria-label="Production filters">
        <button data-filter="all" class="chip active">All</button>
        <button data-filter="single" class="chip">Single Side</button>
        <button data-filter="double" class="chip">Double Side</button>
        <button data-filter="custom" class="chip">Custom</button>
        <button data-filter="ready" class="chip">Ready for Print</button>
        <button data-filter="unverified" class="chip">Unverified</button>
        <button data-filter="wrong" class="chip">Wrong / Review</button>
    </div>

    <div class="cards">
        @foreach($items as $item)
            <article
                id="production-item-{{ $item->id }}"
                class="job-card {{ $item->decision?->decision === 'CORRECT' ? 'approved-card' : '' }} {{ $item->decision?->decision === 'WRONG' ? 'wrong-card' : '' }}"
                data-inline-card
                data-type="{{ $item->design_type }}"
                data-custom="{{ $item->is_custom ? 'yes' : 'no' }}"
                data-ready="{{ $item->ready_for_print ? 'yes' : 'no' }}"
                data-decision="{{ $item->decision?->decision ?? '' }}"
                data-status="{{ $item->match_status }}"
            >
                <div class="job-title">
                    <div>
                        <span>{{ $item->order_number }}</span>
                        <h2>{{ $item->product }}</h2>
                    </div>
                    <strong class="status {{ $item->match_status }}">{{ $item->match_status }}</strong>
                </div>

                <div class="meta">
                    <div><span>Variant</span><b>{{ $item->variant ?: '-' }}</b></div>
                    <div><span>SKU</span><b>{{ $item->sku ?: '-' }}</b></div>
                    <div><span>Qty</span><b>{{ $item->quantity }}</b></div>
                    <div><span>Series</span><b>{{ $item->series_code }}</b></div>
                    <div><span>Type</span><b>{{ $item->design_type }}</b></div>
                    <div><span>Color</span><b>{{ $item->color ?: '-' }}</b></div>
                </div>

                <div class="compare">
                    <div class="art">
                        <label>SHOPIFY ORDER IMAGE</label>
                        @if($item->shopify_image)
                            <img src="{{ $item->shopify_image }}" alt="">
                        @else
                            <div class="placeholder">No Shopify image</div>
                        @endif
                        <p>{{ $item->product }}</p>
                    </div>

                    <div class="drive-grid {{ $item->design_type === 'single' ? 'one' : '' }}">
                        @forelse($item->matches ?? [] as $match)
                            <div class="art">
                                <label>DRIVE {{ strtoupper($match['side'] ?? 'ARTWORK') }}</label>
                                <img src="https://drive.google.com/thumbnail?id={{ $match['id'] }}&sz=w800" alt="">
                                <p>{{ $match['name'] ?? '' }}</p>
                                <small>Score: {{ $match['score'] ?? '-' }}</small>
                                <a target="_blank" href="{{ $match['webViewLink'] ?? '#' }}">Open original</a>
                            </div>
                        @empty
                            <div class="placeholder">{{ $item->is_custom ? 'Custom artwork pending' : 'No Drive artwork matched' }}</div>
                        @endforelse
                    </div>
                </div>

                <div class="job-actions">
                    @if(!$item->is_custom && count($item->matches ?? []))
                        <form data-inline-action method="post" action="/production/{{ $item->id }}/verify">
                            @csrf
                            <input type="hidden" name="decision" value="CORRECT">
                            <button class="approve">Correct</button>
                        </form>
                        <form data-inline-action method="post" action="/production/{{ $item->id }}/verify">
                            @csrf
                            <input type="hidden" name="decision" value="WRONG">
                            <button class="reject">Wrong</button>
                        </form>
                    @else
                        <span class="muted">{{ $item->is_custom ? 'CUSTOM - artwork approval pending' : 'No artwork available to verify' }}</span>
                    @endif

                    <div class="decision" data-inline-decision>
                        @include('production.partials.item-decision', ['item' => $item])
                    </div>
                </div>

                <div class="inline-feedback" data-inline-feedback role="status" aria-live="polite" tabindex="-1"></div>
            </article>
        @endforeach
    </div>
</x-layouts.app>
