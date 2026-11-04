<?php
require __DIR__.'/order-catalog-refresh.php';
$paged=new class extends App\Services\ShopifyService {
    public array $calls=[];
    public function __construct(){}
    public function graphql(string $query,array $variables=[]):array {
        $this->calls[]=$variables;
        $line=['id'=>'page-'.count($this->calls),'sku'=>'2027-GTA-LUC-0001','unfulfilledQuantity'=>1,'product'=>['title'=>'Lucky Bags 2027 GTA','productType'=>'ACL Pro Stamped 2027']];
        if(str_contains($query,'ProductionBacklogLines'))return ['order'=>['lineItems'=>['nodes'=>[$line],'pageInfo'=>['hasNextPage'=>false]]]];
        $more=empty($variables['after']);
        return ['orders'=>['nodes'=>[['id'=>'order-'.count($this->calls),'name'=>'#1','createdAt'=>'2026-11-01','lineItems'=>['nodes'=>[$line],'pageInfo'=>['hasNextPage'=>$more,'endCursor'=>'line-cursor']]]],'pageInfo'=>['hasNextPage'=>$more,'endCursor'=>'order-cursor']]];
    }
};
$result=$paged->getProductionBacklog();
$check($result['complete'] && count($result['items'])===3 && count($paged->calls)===3,'All order and nested line-item pages are fetched before reconciliation');
$check($paged->calls[1]['after']==='line-cursor' && $paged->calls[2]['after']==='order-cursor','Order and line-item cursors advance independently');
$absent=new class extends App\Services\ShopifyService {
    public function __construct(){}
    public function getProductionBacklog(int $first=50):array{return ['items'=>[],'item_count'=>0,'order_count'=>0,'complete'=>true];}
};
$beforeBatch=$lockedBatch->fresh()->payload;
(new App\Services\BacklogSyncService($absent,$drive,$matcher))->sync();
$check(App\Models\ProductionItem::where('shopify_active',true)->count()===0 && $svc->activeItems()->isEmpty(),'A complete Shopify snapshot retires fulfilled or removed lines from the current backlog');
$check($lockedBatch->fresh()->payload===$beforeBatch,'Retiring Shopify lines preserves approved historical print-batch payloads');
$gtaSync->sync();
$check($stale->fresh()->shopify_active===true,'An outstanding line that reappears in Shopify becomes active again');
