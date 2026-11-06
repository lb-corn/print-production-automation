<section class="panel catalog-sync" aria-labelledby="catalog-sync-title">
<div class="catalog-sync-heading">
<div><h2 id="catalog-sync-title">Shopify & Drive catalog</h2><p>Refresh product details and artwork after making changes.</p></div>
<span class="badge">Catalog only</span>
</div>
<div class="catalog-sync-sources">
<div class="catalog-sync-source"><span class="catalog-sync-source-icon" aria-hidden="true">@include('partials.nav-icon',['name'=>'overview'])</span><div><h3>Shopify products</h3><p>Active products, variants and SKUs</p><small>All Bags collection</small></div></div>
<div class="catalog-sync-source"><span class="catalog-sync-source-icon" aria-hidden="true">@include('partials.nav-icon',['name'=>'batches'])</span><div><h3>Google Drive artwork</h3><p>Current files and filenames</p><small>Approved manufacturing folders</small></div></div>
</div>
<div class="catalog-sync-status" aria-label="Last catalog check">
<div><span>Last checked</span><strong>{{$summary?date('d M Y · H:i',$summary['checked_at']):'Not checked yet'}}</strong></div>
<div><span>Products</span><strong>{{$summary?number_format($summary['products']):'—'}}</strong></div>
<div><span>Variants</span><strong>{{$summary?number_format($summary['variants']):'—'}}</strong></div>
</div>
<div class="catalog-sync-footer">
<p>Orders and print queues are managed separately.</p>
<div class="catalog-sync-actions"><a class="chip" href="{{route('admin.settings',array_filter(['section'=>'artwork','plan'=>$plan['token']??null]))}}">View artwork catalog</a>
<form method="post" action="{{route('admin.data-sync')}}" data-catalog-sync>
@csrf<button class="primary" type="submit">Sync catalog</button>
</form></div>
</div>
</section>
