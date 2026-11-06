<?php
// Synthetic fixture only; no customer data or live credentials.
function apparelOrderPayload(array $overrides=[]):array {
    $order=['id'=>'gid://shopify/Order/1001','name'=>'#1001','createdAt'=>'2026-11-01T10:00:00Z','cancelledAt'=>null,'closedAt'=>null,'displayFulfillmentStatus'=>'UNFULFILLED','displayFinancialStatus'=>'PAID','lineItems'=>['nodes'=>[['id'=>'gid://shopify/LineItem/1001','title'=>'Team Jersey','quantity'=>2,'customAttributes'=>[['key'=>'Player Name','value'=>'Test Player'],['key'=>'First Name','value'=>'Test'],['key'=>'Last Name','value'=>'Player'],['key'=>'_internal','value'=>'Private technical value']],'variant'=>['id'=>'gid://shopify/ProductVariant/1001','title'=>'Green / Large','selectedOptions'=>[['name'=>'Color','value'=>'Green'],['name'=>'Size','value'=>'Large']],'image'=>null,'product'=>['id'=>'gid://shopify/Product/1001','title'=>'Team Jersey','handle'=>'team-jersey','tags'=>['Jersey'],'featuredMedia'=>null]]]]]];
    foreach($overrides as $key=>$value)data_set($order,$key,$value);
    return $order;
}
function apparelOrdersResponse(array $orders,bool $hasNext=false,?string $cursor=null):array {
    return ['data'=>['orders'=>['nodes'=>$orders,'pageInfo'=>['hasNextPage'=>$hasNext,'endCursor'=>$cursor]]]];
}
