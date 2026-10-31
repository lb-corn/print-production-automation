<x-layouts.app
    heading="{{ $batch->batch_id }}"
    subheading="Factory production batch"
>
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
            <b class="smallb">{{ $batch->status }}</b>
        </div>

        <div class="stat">
            <span>Orders</span>
            <b>{{ $batch->order_count }}</b>
        </div>

        <div class="stat">
            <span>Artwork Files</span>
            <b>{{ $batch->artwork_file_count }}</b>
        </div>

        <div class="stat">
            <span>Material</span>
            <b>{{ number_format($batch->total_yards, 3) }} yd</b>
        </div>
    </div>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Factory Print Progress</h2>
                <p>
                    Track this batch from the cloud application
                    through the Factory PC and Epson Edge Print.
                </p>
            </div>
        </div>

        @php
            $stepLabels = ['Batch created', 'Factory PC claimed', 'Downloading artwork', 'Artwork prepared', 'Handoff requested', 'Sent to Edge Print', 'Print completed'];
            $stepColors = ['#d9f4df', '#b6e8c3', '#8ed5a4', '#65bd84', '#3da367', '#23834e', '#166534'];
        @endphp
        @if($status === 'FAILED')
            <p class="progress-failure" role="status">Processing failed · Check the Factory Agent log. Printing is not complete.</p>
        @endif
        <ol class="print-progress" aria-label="Factory print progress">
            @foreach($steps as $index=>$step)
                @php
                    $reached = $currentIndex !== false && $index <= $currentIndex;
                    $current = $currentIndex !== false && $index === $currentIndex;
                @endphp
                <li class="print-step {{$reached?'is-reached':''}} {{$current?'is-current':''}} {{$step === 'PRINTED' && $reached?'is-complete':''}}"
                    style="--step-green:{{$stepColors[$index]}}" @if($current) aria-current="step" @endif>
                    <span class="print-step-marker" aria-hidden="true">@if($reached)<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg>@else{{$index+1}}@endif</span>
                    <span class="print-step-copy"><strong>{{$stepLabels[$index]}}</strong><small>{{$current ? ($step === 'PRINTED' ? 'Success' : 'Current stage') : ($reached ? 'Done' : 'Pending')}}</small></span>
                </li>
            @endforeach
        </ol>

        @if($status === 'PREPARED')
            <div style="margin-top:22px;">
                @if(auth()->user()?->role !== 'demo')<button type="button" class="primary" data-print-confirm="HANDOFF" data-confirm-url="{{route('batch.edge-print',$batch)}}">Review &amp; sign handoff →</button>@endif
            </div>

        @elseif($status === 'HANDOFF_REQUESTED')
            <div
                style="
                    margin-top:22px;
                    padding:14px 16px;
                    border:1px solid #e5e7eb;
                    border-radius:10px;
                "
            >
                <strong>Waiting for Factory PC...</strong>

                <div style="margin-top:4px;">
                    The Factory Agent will detect this request
                    and hand the prepared artwork to Epson Edge Print.
                </div>
            </div>

        @elseif($status === 'SENT_TO_RIP')
            <div style="margin-top:22px;">
                <div
                    style="
                        margin-bottom:14px;
                        padding:14px 16px;
                        border:1px solid #e5e7eb;
                        border-radius:10px;
                    "
                >
                    <strong>Artwork sent to Epson Edge Print.</strong>

                    <div style="margin-top:4px;">
                        Review the jobs in Edge Print, RIP them,
                        and physically print the batch.
                    </div>
                </div>

                @if(auth()->user()?->role !== 'demo')<button type="button" class="primary" data-print-confirm="PRINTED" data-confirm-url="{{route('batch.printed',$batch)}}">Confirm physical print completion →</button>@endif
            </div>

        @elseif($status === 'PRINTED')
            <div
                style="
                    margin-top:22px;
                    padding:14px 16px;
                    border:1px solid #d1fae5;
                    border-radius:10px;
                "
            >
                <strong>✓ PRINT COMPLETE</strong>

                @if($batch->completed_at)
                    <div style="margin-top:4px;">
                        Completed:
                        {{ $batch->completed_at->format('M d, Y · g:i A') }}
                    </div>
                @endif
            </div>

        @elseif($status === 'FAILED')
            <div
                style="
                    margin-top:22px;
                    padding:14px 16px;
                    border:1px solid #fecaca;
                    border-radius:10px;
                "
            >
                <strong>Factory processing failed.</strong>

                <div style="margin-top:4px;">
                    Check the Factory Agent log before retrying this batch.
                </div>
            </div>
        @endif
    </section>

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
                <div class="batch-artwork-rows">
                @foreach($order['artworks'] as $artwork)
                    <div class="batch-artwork-row"><span class="artwork-side">{{$artwork['side']}}</span><strong>{{$artwork['material']}}</strong>@include('partials.artwork-filename',['filename'=>$artwork['filename']])</div>
                @endforeach
                </div>
            </article>
        @endforeach
        </div>
    </section>
<section class="panel confirmation-history"><div class="panel-head"><div><h2>Approval &amp; print records</h2><p>Recorded account, operator name, quantities and timestamps</p></div></div>
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