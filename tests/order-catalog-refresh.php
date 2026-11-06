<?php
use App\Services\{ShopifyService,BacklogSyncService};
use App\Models\{ProductionItem,VerificationDecision};
// Reuse the isolated SQLite/fake Drive regression setup; no live integrations.
require __DIR__.'/artwork-matching.php';
$liveShopify=new class extends ShopifyService {
    public array $line=[];
    public function __construct(){}
    public function graphql(string $query,array $variables=[]):array {
        if(!str_contains($query,'variant {'))throw new RuntimeException('Current variant was not requested');
        return ['orders'=>['nodes'=>[['id'=>'order-gta','name'=>'#4251','createdAt'=>'2026-11-01','cancelledAt'=>null,'displayFinancialStatus'=>'PAID','lineItems'=>['nodes'=>[$this->line]]]],'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]]];
    }
};
$liveShopify->line=['id'=>'corrected-gta-order','sku'=>'2027-GTA-ELT-S-0001','variantTitle'=>'Luciano','quantity'=>1,'unfulfilledQuantity'=>1,'product'=>['id'=>'gta-product','title'=>'Lucky Bags 2027 GTA','productType'=>'ACL Pro Stamped 2027'],'variant'=>['id'=>'gta-luciano','sku'=>'2027-GTA-LUC-0001','title'=>'Luciano','product'=>['id'=>'gta-product']]];
$corrected=$liveShopify->getProductionBacklog()['items'][0];
$check($corrected['sku']==='2027-GTA-LUC-0001' && $corrected['order_sku']==='2027-GTA-ELT-S-0001' && $corrected['shopify_variant_id']==='gta-luciano','Order import uses corrected SKU from the exact linked variant and retains checkout SKU as evidence');
$drive->tree=['group-b'=>[$folder('luc','Luciano')],'luc'=>[$file('gta-art','LUC-GTA.jpg')]];
$stale=ProductionItem::create(['line_item_id'=>'corrected-gta-order','order_number'=>'#4251','sku'=>'2027-GTA-ELT-S-0001','product'=>'Lucky Bags 2027 GTA','variant'=>'Luciano','quantity'=>1,'matches'=>[['id'=>'old-gta','side'=>'single']],'match_status'=>'REVIEW']);
VerificationDecision::create(['line_item_id'=>$stale->line_item_id,'decision'=>'CORRECT','drive_file_ids'=>['old-gta'],'updated_by'=>$user->id]);
$gtaSync=new BacklogSyncService($liveShopify,$drive,$matcher);$gtaSync->sync();$stale=$stale->fresh('decision');
$check($stale->sku==='2027-GTA-LUC-0001' && $stale->series_code==='LUC' && $stale->color===null && array_column($stale->matches,'id')===['gta-art'] && !$stale->decision,'Backlog refresh corrects an old unbatched GTA order, finds Luciano artwork and invalidates previous approval');
$check(($stale->artwork_review['catalog_refresh']['order_sku']??null)==='2027-GTA-ELT-S-0001','Saved review records original and corrected catalog identity');
$liveShopify->line['variant']=null;$fallback=$liveShopify->getProductionBacklog()['items'][0];
$check($fallback['sku']==='2027-GTA-ELT-S-0001' && $matcher->match($fallback)['matches']===[] && $matcher->match($fallback)['color']===null,'Deleted variant retains historical data but conflicting Luciano series never becomes a color or silently matches');
$liveShopify->line['variant']=['id'=>'other-variant','sku'=>'2027-GTA-LUC-0001','title'=>'Luciano','product'=>['id'=>'other-product']];
$check($liveShopify->getProductionBacklog()['items'][0]['sku']==='2027-GTA-ELT-S-0001','A variant from another product cannot replace the ordered identity');
$liveShopify->line['variant']=['id'=>'gta-luciano','sku'=>'','title'=>'Luciano','product'=>['id'=>'gta-product']];
$check($liveShopify->getProductionBacklog()['items'][0]['sku']==='','An empty current SKU does not silently reuse the incorrect checkout SKU');
$liveShopify->line['variant']['sku']='2027-GTA-LUC-0001';
$svc->verify($stale,'CORRECT',$user->id);$lockedBatch=$svc->batch($user->id);$lockedBefore=$stale->fresh()->toJson();$payloadBefore=$lockedBatch->fresh()->payload;
$liveShopify->line['variant']['sku']='2027-GTA-ELT-S-0001';$lockedResult=$gtaSync->sync();
$check($lockedResult['processed_skipped']===1 && $stale->fresh()->toJson()===$lockedBefore && $lockedBatch->fresh()->payload===$payloadBefore,'Later catalog edits cannot rewrite a batched order or its approved print snapshot');
echo "Order catalog correction checks passed.\n";
