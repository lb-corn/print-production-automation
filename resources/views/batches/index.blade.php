<x-layouts.app heading="Print Batches" subheading="Factory queue and Epson handoff">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Batches</h2>
                <p>Normal production and linked reprint batches.</p>
            </div>
            <a href="{{ route('history') }}">Print history -></a>
        </div>

        <table>
            <thead>
                <tr><th>Batch</th><th>Type</th><th>Status</th><th>Items</th><th>Orders</th><th>Files</th><th>Created</th><th></th></tr>
            </thead>
            <tbody>
                @forelse($batches as $batch)
                    <tr>
                        <td><b>{{ $batch->batch_id }}</b></td>
                        <td>{{ strtoupper($batch->type) }}</td>
                        <td><span class="badge">{{ $batch->status }}</span></td>
                        <td>{{ $batch->items_count }}</td>
                        <td>{{ $batch->order_count }}</td>
                        <td>{{ $batch->artwork_file_count }}</td>
                        <td>{{ $batch->created_at->format('M d, H:i') }}</td>
                        <td><a href="/batches/{{ $batch->id }}">Open -></a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty">No batches yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-layouts.app>
