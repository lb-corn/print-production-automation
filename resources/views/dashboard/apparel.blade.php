<x-layouts.app heading="Production Overview" subheading="Apparel Production">
<section class="overview-workspace" aria-label="Production overview workspace">
@include('partials.overview-tabs')
<div class="overview-tab-panel" id="production-overview-panel" role="tabpanel" aria-labelledby="overview-tab-apparel" tabindex="0">
<section class="panel apparel-overview-empty"><span class="apparel-overview-icon">@include('partials.nav-icon',['name'=>'apparel-production'])</span><h2>Apparel Production</h2><p>The apparel workspace will be connected in the next integration phase. No apparel orders or metrics have been imported.</p></section>
</div>
</section>
</x-layouts.app>
