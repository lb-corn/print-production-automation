<x-layouts.app heading="{{ $batch->batch_id }}" subheading="Factory production batch">
    @php
        $status = $batch->status;
        $steps = ['QUEUED', 'CLAIMED', 'DOWNLOADING', 'PREPARED', 'HANDOFF_REQUESTED', 'SENT_TO_RIP', 'PRINTED'];
        $currentIndex = array_search($status, $steps, true);
    @endphp

    <div class="stats compact">
        <div class="stat"><span>Status</span><b class="smallb">{{ $batch->status }}</b></div>
        <div class="stat"><span>Type</span><b class="smallb">{{ strtoupper($batch->type) }}</b></div>
        <div class="stat"><span>Orders</span><b>{{ $batch->order_count }}</b></div>
        <div class="stat"><span>Material</span><b>{{ number_format($batch->total_yards, 3) }} yd</b></div>
    </div>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Factory Print Progress</h2>
                <p>Hot-folder transfer is not proof of physical printing. Auto Print must remain OFF.</p>
            </div>
        </div>

        <div class="progress-list">
            @foreach($steps as $index => $step)
                <div>
                    <span>{{ $currentIndex !== false && $currentIndex >= $index ? 'DONE' : 'WAIT' }}</span>
                    <strong>{{ str_replace('_', ' ', $step) }}</strong>
                </div>
            @endforeach
        </div>

        @if($status === 'PREPARED')
            <form method="POST" action="{{ route('batch.edge-print', $batch) }}" onsubmit="return confirm('Confirm explicit handoff to Epson Edge Print hot folder? Auto Print must remain OFF.')" class="top-gap">
                @csrf
                <button type="submit" class="btn primary">Request Edge Print Handoff</button>
            </form>
        @elseif($status === 'HANDOFF_REQUESTED')
            <div class="callout top-gap"><strong>Waiting for Factory PC.</strong><span>The agent will copy prepared artwork to the Edge Print hot folder.</span></div>
        @elseif($status === 'SENT_TO_RIP')
            <div class="callout top-gap">
                <strong>Artwork sent to Epson Edge Print.</strong>
                <span>Review in Edge Print, RIP, physically print, then record actual quantities below.</span>
            </div>

            <form method="POST" action="{{ route('batch.printed', $batch) }}" onsubmit="return confirm('Record physical print results for this batch? This cannot be duplicated.')" class="completion-form">
                @csrf

                @foreach($batch->items as $batchItem)
                    <div class="batch-order result-row">
                        <div>
                            <b>{{ $batchItem->productionItem->order_number }} - {{ $batchItem->productionItem->product }}</b>
                            <span>Batch qty {{ $batchItem->quantity }} - Shopify line {{ $batchItem->shopify_line_item_id }}</span>
                        </div>
                        <div class="result-inputs">
                            <label>Printed<input name="items[{{ $batchItem->id }}][completed_quantity]" type="number" min="0" max="{{ $batchItem->quantity }}" value="{{ $batchItem->quantity }}"></label>
                            <label>Failed<input name="items[{{ $batchItem->id }}][failed_quantity]" type="number" min="0" max="{{ $batchItem->quantity }}" value="0"></label>
                            <label>Note<input name="items[{{ $batchItem->id }}][reason]" type="text" maxlength="500"></label>
                        </div>
                    </div>
                @endforeach

                <button type="submit" class="btn primary">Record Physical Print Results</button>
            </form>
        @elseif($status === 'PRINTED')
            <div class="callout success top-gap">
                <strong>Print results recorded.</strong>
                @if($batch->print_confirmed_at)
                    <span>Confirmed {{ $batch->print_confirmed_at->format('M d, Y g:i A') }}</span>
                @endif
            </div>
        @elseif($status === 'FAILED')
            <div class="callout danger top-gap">
                <strong>Factory processing failed.</strong>
                <span>{{ $batch->failure_reason ?: 'Check the Factory Agent log before retrying this batch.' }}</span>
            </div>
        @endif
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Batch Items</h2>
                <p>Durable allocation and print result records for this batch.</p>
            </div>
        </div>

        @foreach($batch->items as $batchItem)
            <div class="batch-order">
                <div>
                    <b>{{ $batchItem->productionItem->order_number }} - {{ $batchItem->productionItem->product }}</b>
                    <span>
                        {{ $batchItem->productionItem->variant }}
                        - {{ $batchItem->productionItem->series_code }}
                        - Qty {{ $batchItem->quantity }}
                        - {{ $batchItem->status }}
                    </span>
                </div>

                <div class="pills">
                    @foreach($batchItem->artworks as $artwork)
                        <span>{{ $artwork['side'] }} - {{ $artwork['material'] }} - {{ $artwork['filename'] }}</span>
                    @endforeach
                </div>
            </div>
        @endforeach
    </section>

    @if($batch->histories->count())
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Print History</h2>
                    <p>Permanent result records against batch and Shopify line item.</p>
                </div>
            </div>

            <table>
                <thead><tr><th>Line Item</th><th>Result</th><th>Qty</th><th>Recorded</th><th>Reason</th></tr></thead>
                <tbody>
                    @foreach($batch->histories as $history)
                        <tr>
                            <td>{{ $history->shopify_line_item_id }}</td>
                            <td>{{ $history->result }}</td>
                            <td>{{ $history->quantity }}</td>
                            <td>{{ $history->recorded_at->format('M d, Y g:i A') }}</td>
                            <td>{{ $history->reason }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</x-layouts.app>
