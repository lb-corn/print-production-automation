<?php
require __DIR__.'/bulk-review.php';
$catalog=app(App\Services\MaterialCatalog::class);
$single=new App\Models\ProductionItem(['series_code'=>'PSE','design_type'=>'single']);
$double=new App\Models\ProductionItem(['series_code'=>'PSN','design_type'=>'double']);
$html=view('partials.review-material-labels',['item'=>$single])->render();
$check(substr_count($html,'class="review-material-badge"')===1 && str_contains($html,'Rave9') && !str_contains($html,'Vmt'),'Single-sided review shows only its printed material, without a backing badge');
$html=view('partials.review-material-labels',['item'=>$double])->render();
$check(substr_count($html,'class="review-material-badge"')===2 && str_contains($html,'Front · Rave9') && str_contains($html,'Back · Turbo6'),'Double-sided review shows separate front and back sheet materials');
$dynamic=new class extends App\Services\MaterialCatalog {
    public string $material='Updated sheet material';
    public function rows(bool $fresh=false):array{return ['PSE'=>['front'=>$this->material,'back'=>'Not printed','type'=>'single']];}
};
$app->instance(App\Services\MaterialCatalog::class,$dynamic);
$check(str_contains(view('partials.review-material-labels',['item'=>$single])->render(),'Updated sheet material'),'Material badges use the live catalog rather than hardcoded names');
$dynamic->material='<script>bad</script>';
$escaped=view('partials.review-material-labels',['item'=>$single])->render();
$check(!str_contains($escaped,'<script>') && str_contains($escaped,'&lt;script&gt;'),'Sheet material labels escape untrusted text');
$app->instance(App\Services\MaterialCatalog::class,$catalog);
$previewItems=collect();
foreach([['PSE','single','approved'],['PSN','double','pending']] as $index=>[$code,$type,$state]){
    $item=new App\Models\ProductionItem(['series_code'=>$code,'design_type'=>$type,'product'=>'Lucky Bags Custom Design With A Longer Product Name','variant'=>'Pro Snipers / Grey','sku'=>'2027-TEST-'.$code,'quantity'=>1,'match_status'=>'MISSING']);
    $item->id=9000+$index;$item->order_number='#4280';$item->workflow_state=$state;$item->approval_current=true;$item->valid_artwork=false;$item->ready_for_print=false;$item->production_batch=null;$item->display_matches=[];
    $previewItems->push($item);
}
$html=view('production.verification',['items'=>$previewItems,'reviewSummary'=>['approved'=>1,'rejected'=>0,'pending'=>1,'in_print'=>0,'completed'=>0,'failed'=>0]])->render();
$check(str_contains($html,'>Verified</span>') && str_contains($html,'>Not verified</span>'),'Verified and Not verified remain visible alongside material badges');
if(in_array('--preview',$argv,true))file_put_contents(__DIR__.'/../tmp/review-materials-preview.html',$html);
