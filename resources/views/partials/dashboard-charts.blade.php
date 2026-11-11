@php
    $chartTotal=array_sum(array_column($charts['states'],'value'));
    $companyMax=max(1,...array_column($charts['companies'],'value'));
    $trendMax=max(1,...array_column($charts['trend'],'value'));
    $offset=0;
@endphp
<div class="dashboard-charts" aria-label="Production charts">
<section class="dashboard-chart panel">
    <div class="chart-heading"><h2>Production status</h2><span>Order lines</span></div>
    <div class="chart-donut-layout"><svg viewBox="0 0 160 160" class="status-donut" role="img" aria-label="Production status: {{$chartTotal}} order lines">
    <circle cx="80" cy="80" r="58" fill="none" stroke="#edf3f5" stroke-width="18"/>
    @foreach($charts['states'] as $segment)
    @php $length=$chartTotal?$segment['value']/$chartTotal*364.425:0; @endphp
    @if($length>0)<circle cx="80" cy="80" r="58" fill="none" stroke="{{$segment['color']}}" stroke-width="18" stroke-dasharray="{{$length}} {{364.425-$length}}" stroke-dashoffset="{{-$offset}}" transform="rotate(-90 80 80)"><title>{{$segment['label']}}: {{$segment['value']}}</title></circle>@endif
    @php $offset+=$length; @endphp
    @endforeach
    <text x="80" y="79" text-anchor="middle" class="donut-total">{{$chartTotal}}</text><text x="80" y="98" text-anchor="middle" class="donut-caption">order lines</text>
    </svg><ul class="chart-legend">@foreach($charts['states'] as $segment)<li><span><i style="background:{{$segment['color']}}"></i>{{$segment['label']}}</span><strong>{{$segment['value']}}</strong></li>@endforeach</ul></div>
    @if(!$chartTotal)<p class="chart-empty">No order lines for this company yet.</p>@endif
</section>
<section class="dashboard-chart panel">
    <div class="chart-heading"><h2>{{$charts['companyTitle']}}</h2><span>{{$charts['companyHint']}}</span></div>
    <div class="company-chart">@foreach($charts['companies'] as $company)<div class="company-chart-row"><div><span><i style="background:{{$company['color']}}"></i>{{$company['label']}}</span><strong>{{$company['value']}} <small>lines</small></strong></div><div class="company-chart-track"><span style="width:{{$company['value']/$companyMax*100}}%;background:{{$company['color']}}"></span></div></div>@endforeach</div>
    <div class="chart-total-line"><span>Total {{ $charts['unit'] }}</span><strong>{{$charts['total']}}</strong></div>
</section>
<section class="dashboard-chart panel">
    <div class="chart-heading"><h2>{{$charts['trendTitle']}}</h2><span>{{$charts['trendHint']}}</span></div>
    <div class="trend-chart" role="img" aria-label="{{$charts['trendHint']}}: {{implode(', ',array_map(fn($point)=>$point['label'].' '.$point['value'],$charts['trend']))}}">
    @foreach($charts['trend'] as $point)<div class="trend-column"><div class="trend-bar-space"><span class="trend-value">{{$point['value']}}</span><span class="trend-bar" style="height:{{$point['value']/$trendMax*100}}%;min-height:{{$point['value']>0?'3px':'0'}}"></span></div><span class="trend-month">{{$point['label']}}</span></div>@endforeach
    </div>
</section>
</div>
