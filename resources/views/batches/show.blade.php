<x-layouts.app

    heading="{{ $batch->batch_id }}"
    subheading="{{$batch->status==='CANCELLED'?'Cancelled history · not queued':'Factory production batch'}}"
>
@if(($relatedBatches??collect())->count()>1)<section class="panel"><h2>Related material batches</h2><p>Each material is delivered and confirmed separately. A double-sided order completes only after all of its material batches are printed.</p><nav class="related-material-batches" aria-label="Related material batches">@foreach($relatedBatches as $related)<a href="{{route('batch.show',$related->id)}}" @if($related->id===$batch->id) aria-current="page" @endif>{{$related->payload['material_group']}} · {{$related->status}}</a>@endforeach</nav></section>@endif
    @php
        $status = $batch->status;

        $steps = [
            'QUEUED',
            'CLAIMED',
            'DOWNLOADING',
            'PREPARED',
            'HANDOFF_REQUESTED',
            'SENT_TO_RIP',
            'PRINTED',
        ];

        $currentIndex = array_search($status, $steps, true);
    @endphp

    <div class="stats compact">
        <div class="stat">
            <span>Status</span>
            <b class="smallb" data-batch-status>{{ $batch->status }}</b>
        </div>

        <div class="stat">
            <span>{{$status==='CANCELLED'?'Original items · history':'Orders'}}</span>
            <b>{{ $batch->order_count }}</b>
        </div>

        <div class="stat">
            <span>{{$status==='CANCELLED'?'Original files · history':'Artwork Files'}}</span>
            <b>{{ $batch->artwork_file_count }}</b>
        </div>

        <div class="stat">
            <span>Material</span>
            <b>{{ number_format($batch->total_yards, 3) }} yd</b>
        </div>
    </div>

    @if($status==='QUEUED' && !$batch->claimed_at && !$batch->completed_at && !$batch->confirmations()->exists() && auth()->user()?->role!=='demo')
    <p class="admin-notice">Need an artwork correction? Use <b>Return this item to Artwork Review</b> beside that item below. Other items stay queued.</p>
    @elseif($status==='CANCELLED')
    <p class="admin-notice"><strong>Cancelled batch history — not in the print queue.</strong> Items were returned for artwork review. <a href="{{route('production')}}">Open Artwork Verification →</a>
@if($batch->payload['returned_to_review']['replacement_batch_id']??null)<a href="{{route('batch.show',$batch->payload['returned_to_review']['replacement_batch_id'])}}">View replacement batch →</a>@endif</p>
    @endif
    @if($status!=='CANCELLED')
    @include('partials.factory-progress')

    @endif
    @if($status==='CANCELLED')<details class="panel cancelled-batch-history"><summary>Original batch contents · history only</summary>@endif
    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Batch contents</h2>
                <p>
                    Locked orders, artwork sides and materials for this batch.
                </p>
            </div>
        </div>

        <div class="batch-contents">
        @foreach($batch->payload['orders'] ?? [] as $order)
            <article class="batch-content-item">
                <div class="batch-content-title"><div><span>{{$order['order']}}</span><h3 title="{{$order['product']}}">{{$order['product']}}</h3><p>{{$order['variant'] ?? 'Standard'}} · {{$order['series_code']}}</p></div><strong class="batch-quantity">{{$order['quantity']}} <small>{{$order['quantity'] === 1 ? 'set' : 'sets'}}</small></strong></div>
                @if($status==='QUEUED' && !$batch->claimed_at && !$batch->completed_at && !$batch->confirmations()->exists() && auth()->user()?->role!=='demo' && isset($itemIds[$order['line_item_id']]))
                <form method="post" action="{{route('batch.return-item',[$batch->id,$itemIds[$order['line_item_id']]])}}" data-return-batch data-return-scope="item" data-batch-id="{{$batch->id}}">@csrf<input type="hidden" name="return_confirmed" value="1"><button class="chip" type="submit">Return this item to Artwork Review</button></form>
                @endif
                <div class="batch-artwork-rows">
                @foreach($order['artworks'] as $artwork)
                    <div class="batch-artwork-row"><span class="artwork-side">{{$artwork['side']}}</span><strong>{{$artwork['material']}}</strong>@include('partials.artwork-filename',['filename'=>$artwork['filename']])@if($artwork['print_label']??null)<code class="batch-print-label">{{$artwork['print_label']}}</code>@endif</div>
                @endforeach
                </div>
            </article>
        @endforeach
        </div>
    </section>
@if($status==='CANCELLED')</details>@endif
<section class="panel confirmation-history"><div class="panel-head"><div><h2>{{$status==='CANCELLED'?'Historical approval records':'Approval & print records'}}</h2><p>Recorded account, operator name, quantities and timestamps</p></div></div>
@forelse($batch->confirmations as $confirmation)
<div class="confirmation-record"><strong>{{$confirmation->action === 'HANDOFF' ? 'Handoff authorized' : 'Physical print completed'}}</strong><p>{{$confirmation->signer_name}} · account: {{$confirmation->account_name}} · {{$confirmation->confirmed_at->timezone('Asia/Colombo')->format('Y-m-d H:i:s T')}}</p><span>{{$confirmation->order_count}} items · {{$confirmation->total_sets}} sets · {{$confirmation->artwork_file_count}} artwork files</span></div>
@empty<p class="muted">No signed print confirmations recorded yet.</p>@endforelse
@foreach($batch->payload['orders'] ?? [] as $order)
@if(isset($order['artwork_approval']))<div class="confirmation-record"><strong>{{$order['order']}} · {{$order['product']}}</strong><p>Artwork approved by {{$order['artwork_approval']['account_name'] ?? 'Recorded account'}} · {{$order['artwork_approval']['approved_at'] ?? 'Time unavailable'}}</p></div>@endif
@endforeach
</section>
<dialog id="print-confirmation" class="print-confirmation" aria-labelledby="print-confirm-title">
@if(auth()->user()?->role !== 'demo')<form method="post" data-signed-print-form>
@csrf
<h2 id="print-confirm-title">Review and sign</h2><p data-confirm-description></p>
<div class="confirmation-facts"><strong>{{$batch->batch_id}}</strong><span>{{$batch->order_count}} items · {{collect($batch->payload['orders'] ?? [])->sum('quantity')}} sets · {{$batch->artwork_file_count}} artwork files</span></div>
<input type="hidden" name="confirmed_order_count" value="{{$batch->order_count}}"><input type="hidden" name="confirmed_artwork_file_count" value="{{$batch->artwork_file_count}}"><input type="hidden" name="payload_sha256" value="{{hash('sha256',json_encode($batch->payload))}}">
<label for="print-signer">Your full name</label><input id="print-signer" name="signer_name" required minlength="2" maxlength="100" autocomplete="name">
<label class="confirmation-check"><input type="checkbox" name="review_confirmed" value="1" required><span>I checked the artwork, front/back pairing, material, quantities and the batch shown above.</span></label>
<label for="print-phrase">Type <strong data-confirm-phrase></strong> to confirm</label><input id="print-phrase" name="confirmation_phrase" required autocomplete="off">
<p class="muted">Recorded under {{auth()->user()->name ?? 'your signed-in account'}} with server date/time. This records operator confirmation; it is not a cryptographic signature.</p>
<div class="confirmation-actions"><button type="button" class="chip" data-cancel-print>Cancel</button><button type="submit" class="primary">Sign &amp; confirm</button></div>
</form>@endif</dialog>
</x-layouts.app>