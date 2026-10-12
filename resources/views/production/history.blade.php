<x-layouts.app heading="Print History" subheading="Search completed orders and create explicit reprints">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Completed Production</h2>
                <p>Reprints create new linked batches and do not modify Shopify fulfillment.</p>
            </div>
        </div>

        <form class="inline-form" method="get" action="{{ route('history') }}">
            <input class="search-input" name="q" value="{{ $q }}" placeholder="Order, SKU, product or Shopify line item">
            <button class="primary" type="submit">Search</button>
        </form>

        <table>
            <thead>
                <tr><th>Order</th><th>Product</th><th>SKU</th><th>Completed</th><th>Reprint</th></tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    @php($completed = $svc->completedNormalQuantity($item))
                    <tr>
                        <td>{{ $item->order_number }}</td>
                        <td>{{ $item->product }}</td>
                        <td>{{ $item->sku }}</td>
                        <td>{{ $completed }}</td>
                        <td>
                            <form class="reprint-form" method="post" action="{{ route('reprint.create', $item) }}" onsubmit="return confirm('Create a reprint batch for this completed line item?')">
                                @csrf
                                <input name="quantity" type="number" min="1" max="{{ $completed }}" value="1">
                                <input name="reason" type="text" maxlength="500" placeholder="Reason" required>
                                <label><input name="confirm" type="checkbox" required> Confirm</label>
                                <button class="primary" type="submit">Reprint</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No completed print history found.</td></tr>
                @endforelse
            </tbody>
        </table>

        {{ $items->links() }}
    </section>
</x-layouts.app>
