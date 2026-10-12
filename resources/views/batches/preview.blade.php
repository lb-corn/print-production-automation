<x-layouts.app heading="Confirm Production Batch" subheading="Review orders, quantities, artwork, sides and materials before locking">
    <div class="stats compact">
        <div class="stat"><span>Selected Orders</span><b>{{ $summary['approved_orders'] }}</b></div>
        <div class="stat"><span>Total Sets</span><b>{{ $summary['total_sets'] }}</b></div>
        <div class="stat"><span>Printable Sides</span><b>{{ $summary['printable_sides'] }}</b></div>
        <div class="stat"><span>Material</span><b>{{ number_format($summary['total_yards'], 3) }} yd</b></div>
    </div>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Batch Review</h2>
                <p>Creating this batch allocates only the quantities shown here.</p>
            </div>
        </div>

        @if($items->isEmpty())
            <div class="empty">No eligible quantities selected.</div>
        @else
            <form method="post" action="{{ route('batch.create') }}" onsubmit="return confirm('Create this production batch with only the selected quantities?')">
                @csrf

                @foreach($items as $item)
                    <input type="hidden" name="items[{{ $item->id }}]" value="{{ $item->selected_quantity }}">
                    <div class="batch-order">
                        <div>
                            <b>{{ $item->order_number }} - {{ $item->product }}</b>
                            <span>{{ $item->variant }} - {{ $item->series_code }} - Qty {{ $item->selected_quantity }}</span>
                        </div>

                        <div class="pills">
                            @foreach($item->matches as $match)
                                <span>
                                    {{ strtoupper($match['side'] ?? 'PRINT') }}
                                    -
                                    {{ $match['name'] ?? 'Artwork' }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div class="button-row">
                    <a class="btn secondary" href="{{ route('queue') }}">Back</a>
                    <button class="primary" type="submit">Create Batch</button>
                </div>
            </form>
        @endif
    </section>
</x-layouts.app>
