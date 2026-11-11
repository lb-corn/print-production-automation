<?php
use App\Http\Controllers\FilenameCleanupController;
use App\Models\{ProductionItem,PrintBatch};
use App\Services\ProductionService;
require __DIR__.'/artwork-standardization.php';
auth()->setUser($admin);
$cleanup=new FilenameCleanupController();$writer->permission=true;$writer->failAfter=null;
$cleanupRow=[...$row,'catalog_key'=>hash('sha256','cleanup-one'),'sku'=>'2027-DUC-PSE-0001','product'=>'Lucky Bags 2027 Duck Camo','variant'=>'Pro Sniper Elite / Red'];
$canonicalRow=[...$cleanupRow,'catalog_key'=>hash('sha256','cleanup-standard'),'sku'=>'2027-ZEB-PSE-0001','product'=>'Lucky Bags 2027 Zebra'];
$lockedRow=[...$cleanupRow,'catalog_key'=>hash('sha256','cleanup-locked'),'sku'=>'2027-BAT-PSE-0001','product'=>'Lucky Bags 2027 Bat'];
$cleanupFolder=['kind'=>'series_folder','id'=>'pse','name'=>'PSE','path'=>'GROUP B (Carpet) / PSE'];
$work=[...$source('cleanup-work','PSE-DUC-RED.jpg'),'canRename'=>true];
$canonical=[...$source('cleanup-standard',$standardName('LB-PSE-ZEB-RED-SINGLE','cleanup-standard')),'canRename'=>true];
$locked=[...$source('cleanup-locked','PSE-BAT-RED.jpg'),'canRename'=>true];
$unresolved=$source('cleanup-unresolved','some random image.jpg');
$shopify->rows=[$cleanupRow,$canonicalRow,$lockedRow];$drive->index=[$cleanupFolder,$work,$canonical,$locked,$unresolved];
$guard=PrintBatch::create(['batch_id'=>'cleanup-guard','status'=>'QUEUED','order_count'=>1,'artwork_file_count'=>1,'payload'=>['orders'=>[['line_item_id'=>'cleanup-guard-only','artworks'=>[['drive_file_id'=>$locked['id']]]]]]]);
$request=Illuminate\Http\Request::create('/admin/settings','GET',['section'=>'filenames']);$request->setUserResolver(fn()=>$admin);
$writesBefore=$writer->writes;$page=$cleanup->index($request);$data=$page->getData();$html=$page->render();
$cachedCatalog=$service->scan($admin->id); // Simulate an Artwork matching page opened before rename.
$check(array_column($data['filenameEntries'],'id')===[$work['id'],$locked['id']] && $writer->writes===$writesBefore,'Cleanup lists only nonstandard files with suggestions, excluding canonical and unidentified files');
$dom=new DOMDocument();@$dom->loadHTML($html,LIBXML_NONET);$xpath=new DOMXPath($dom);
$check($xpath->query('//form[@data-filename-process]')->length===1 && str_contains($html,'Process suggested name') && str_contains($html,'In print batch') && !str_contains($html,'type="password"') && !str_contains($html,'name="ids[]"'),'Cleanup has one-click row actions, clear batch protection and no password/bulk preview UI');
$item=ProductionItem::create(['line_item_id'=>'cleanup-order','quantity'=>1,...array_intersect_key($cleanupRow,array_flip(['sku','product','variant'])),...$matcher->match($cleanupRow,$drive->index)]);app(ProductionService::class)->verify($item,'CORRECT',$admin->id);
$batchesBefore=PrintBatch::all()->toJson();
$processRequest=function($token,$id,$user) { $r=Illuminate\Http\Request::create('/admin/filename-cleanup/process','POST',['plan'=>$token,'file_id'=>$id]);$r->setUserResolver(fn()=>$user);return $r; };
$response=$cleanup->process($processRequest($data['plan']['token'],$work['id'],$admin));$item=$item->fresh('decision');
$check($response->isRedirect(route('admin.settings',['section'=>'filenames','partner'=>'lucky'])) && $writer->writes===$writesBefore+1 && $item->matches[0]['name']===$standardName('LB-PSE-DUC-RED-SINGLE',$work['id']) && !$item->decision && str_contains(session('ok'),'Renamed PSE-DUC-RED.jpg'),'One-click action updates Drive and the order with a clear success result and fresh approval requirement');
$check(PrintBatch::all()->toJson()===$batchesBefore,'One-click cleanup never changes existing queues or batch snapshots');
$refreshedCatalog=$store->latestPreview($admin->id);
$refreshedFile=collect($refreshedCatalog['index'])->firstWhere('id',$work['id']);
$check($refreshedCatalog['token']!==$cachedCatalog['token'] && $refreshedFile['name']===$item->matches[0]['name'] && str_ends_with($refreshedFile['path'],$refreshedFile['name']) && str_contains(session('ok'),'No Data sync is needed'),'Confirmed rename automatically publishes the changed file in the catalog without manual Data sync');
try{$store->load($cachedCatalog['token'],$admin->id);throw new LogicException('Stale preview accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Pre-rename preview tokens cannot perform further writes');}
$oldLink=Illuminate\Http\Request::create('/admin/settings','GET',['section'=>'artwork','plan'=>$cachedCatalog['token']]);$oldLink->setUserResolver(fn()=>$admin);
$oldPage=(new App\Http\Controllers\ArtworkNamingController())->index($oldLink,$store)->getData();
$check($oldPage['plan']['token']===$refreshedCatalog['token'] && collect($oldPage['plan']['index'])->firstWhere('id',$work['id'])['name']===$refreshedFile['name'],'Bookmarked pre-rename Artwork matching pages automatically use the refreshed catalog');
$newPage=$cleanup->index($request);$check(!in_array($work['id'],array_column($newPage->getData()['filenameEntries'],'id'),true),'Successfully renamed file disappears from the nonstandard list');
$cleanup->process($processRequest($data['plan']['token'],$work['id'],$admin));$check($writer->writes===$writesBefore+1 && str_contains(session('error'),'No files were renamed'),'Replayed row actions cannot rename again');
$cleanup->process($processRequest($newPage->getData()['plan']['token'],$locked['id'],$admin));$check($writer->writes===$writesBefore+1 && PrintBatch::all()->toJson()===$batchesBefore,'Direct submission cannot bypass a protected row');
$other=[...$source('cleanup-second','PSE-DUC-RED.jpeg'),'canRename'=>true];$drive->index=[$cleanupFolder,$other];$shopify->rows=[$cleanupRow];$pending=$cleanup->index($request)->getData()['plan'];
$drive->index[1]['version']='99';$cleanup->process($processRequest($pending['token'],$other['id'],$admin));$check($writer->writes===$writesBefore+1 && str_contains(session('error'),'No files were renamed'),'Changed source metadata returns clear feedback without writing');
$drive->index[1]=$other;$pending=$cleanup->index($request)->getData()['plan'];$writer->failAfter=$writer->writes;
$cleanup->process($processRequest($pending['token'],$other['id'],$admin));$check(str_contains(session('warning'),'Operation stopped') && $writer->writes===$writesBefore+1,'Uncertain rename outcomes return a visible warning and never auto-retry');$writer->failAfter=null;
config(['services.google_drive.rename_enabled'=>false]);$disabled=$cleanup->index($request)->render();$check(str_contains($disabled,'Renaming is disabled on this server') && !str_contains($disabled,'data-filename-process'),'Server-disabled renaming explains the blocker instead of offering a dead action');config(['services.google_drive.rename_enabled'=>true]);
try{$cleanup->process($processRequest($pending['token'],$other['id'],$operator));throw new LogicException('Operator renamed');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===403,'Normal users cannot invoke one-click renaming');}
$route=app('router')->getRoutes()->getByName('admin.filename-cleanup.process');$check(in_array(App\Http\Middleware\RequireFeature::class,$route->gatherMiddleware()) && in_array(App\Http\Middleware\PortalWriteLock::class,$route->gatherMiddleware()),'Cleanup retains Admin authentication and serialization against batching');
// Edited targets must remain valid for the same exact product and variant.
$drive->index=[$cleanupFolder,$other];$shopify->rows=[$cleanupRow];$editable=$cleanup->index($request);$editPlan=$editable->getData()['plan'];$editHtml=$editable->render();
$check(str_contains($editHtml,'Current name in Drive') && str_contains($editHtml,'name="suggested_name"') && str_contains($editHtml,'Lucky Bags 2027 Duck Camo') && str_contains($editHtml,'filename-cleanup-search'),'Cleanup exposes product search, authoritative Drive names and editable suggestions');
$writesBefore=$writer->writes;
foreach(['LB-LUC-DUC-RED-SINGLE.jpeg','LB-PSE-DUC-BLUE-SINGLE.jpeg','LB-PSE-DUC-RED-BACK.jpeg','LB-PSE-DUC-RED-SINGLE.png','../LB-PSE-DUC-RED-SINGLE.jpeg'] as $invalid) {
    $r=$processRequest($editPlan['token'],$other['id'],$admin);$r->merge(['suggested_name'=>$invalid]);$cleanup->process($r);
    $check($writer->writes===$writesBefore && str_contains(session('error'),'No files were renamed'),'Edited name rejects wrong identity, variant, side, extension or path: '.$invalid);
}
$custom='LB-PSE-DUCK-CAMO-RED-SINGLE.jpeg';
$service->setSuggestedName($editPlan['token'],$admin->id,$other['id'],$custom);
$drive->index[]=[...$other,'id'=>'edited-name-collision','name'=>$custom,'path'=>$cleanupFolder['path'].' / '.$custom];
$r=$processRequest($editPlan['token'],$other['id'],$admin);$r->merge(['suggested_name'=>$custom]);$cleanup->process($r);
$check($writer->writes===$writesBefore && str_contains(session('error'),'No files were renamed'),'An edited target is checked again when a destination appears after the edit');array_pop($drive->index);
$r=$processRequest($editPlan['token'],$other['id'],$admin);$r->merge(['suggested_name'=>$custom]);$cleanup->process($r);
$check($writer->writes===$writesBefore+1 && $drive->index[1]['name']===$custom && $matcher->match($cleanupRow,$drive->index)['matches'][0]['name']===$custom && str_contains(session('ok'),$custom),'A valid edited design name is written to Drive and remains matchable in the app');
$check(!in_array($other['id'],array_column($cleanup->index($request)->getData()['filenameEntries'],'id'),true),'An accepted custom filename is remembered instead of being suggested for cleanup again');
$drive->index[1]['version']='changed-outside-app';$check(in_array($other['id'],array_column($cleanup->index($request)->getData()['filenameEntries'],'id'),true),'A remembered custom filename does not hide a file whose metadata changed later');
$sharedRow=[...$cleanupRow,'catalog_key'=>hash('sha256','edited-shared'),'product'=>'Lucky Bags 2027 Broken Glass'];$shopify->rows=[$cleanupRow,$sharedRow];$drive->index=[$cleanupFolder,[...$source('edited-shared-source','PSE-DUCK-CAMO-RED.jpeg'),'canRename'=>true]];$sharedPlan=$service->scan($admin->id,true);
try{$service->setSuggestedName($sharedPlan['token'],$admin->id,'edited-shared-source','LB-PSE-DUC-RED-SINGLE.jpeg');throw new LogicException('Ambiguous short name accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422 && str_contains($e->getMessage(),'shared'),'Edited names cannot remove product disambiguation when distinct designs share a SKU code');}
$settingsRequest=Illuminate\Http\Request::create('/admin/settings','GET',['section'=>'users']);$settingsRequest->setUserResolver(fn()=>$admin);$usersHtml=(new App\Http\Controllers\AdminSettingsController())->index($settingsRequest)->render();
$check(str_contains($usersHtml,'Manage Users') && str_contains($usersHtml,'Create user') && substr_count($usersHtml,'<main>')===1 && !str_contains($usersHtml,'Admin only'),'Manage Users renders inside the shared settings layout without Admin-only badges');
$accountHtml=view('partials.account-menu')->render();$check(!str_contains($accountHtml,'>Users ') && str_contains($accountHtml,'Admin Settings'),'Account menu has one Admin Settings entry instead of a separate Users item');
$settingsRequest->setUserResolver(fn()=>$operator);try{(new App\Http\Controllers\AdminSettingsController())->index($settingsRequest);throw new LogicException('Operator accessed users tab');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===403,'Removing badges does not grant normal users access to Manage Users');}
$cloverRow=[...$cleanupRow,'catalog_key'=>hash('sha256','reported-clover'),'sku'=>'2027-RET7-CLV-0001','product'=>'Lucky Bags 2027 Retro 7','variant'=>'Clovers'];$lucRow=[...$cloverRow,'catalog_key'=>hash('sha256','reported-luciano'),'sku'=>'2027-RET7-LUC-0001','variant'=>'Luciano'];
$cloverFolder=['kind'=>'series_folder','id'=>'reported-clv','name'=>'Clover','path'=>'GROUP B (Carpet) / Clover'];$lucFolder=['kind'=>'series_folder','id'=>'reported-luc','name'=>'Luciano','path'=>'GROUP B (Carpet) / Luciano'];
$short=[...$source('reported-short-clover','LB-CLV-RET7-SINGLE.jpg'),'parent_id'=>$cloverFolder['id'],'path'=>$cloverFolder['path'].' / LB-CLV-RET7-SINGLE.jpg','canRename'=>true];
$luc=[...$source('10W9EHnm4xOr4OJ4jiHQuCo_4NtOSpSIG','LB-LUC-RET7-BLACK-SINGLE-ID-A170B0A272A7.jpg'),'parent_id'=>$lucFolder['id'],'path'=>$lucFolder['path'].' / LB-LUC-RET7-BLACK-SINGLE-ID-A170B0A272A7.jpg','canRename'=>true];
$shopify->rows=[$cloverRow,$lucRow];$drive->index=[$cloverFolder,$lucFolder,$short,$luc];$reported=$cleanup->index($request)->getData()['filenameEntries'];
$check(array_column($reported,'id')===[$short['id']] && $reported[0]['proposal']['target']===$standardName('LB-CLV-RET7-SINGLE',$short['id']),'Cleanup hides the reported correct Luciano name and lists short Clover for its own ID-suffixed suggestion');
$check($matcher->isStandardFilename($luc) && !$matcher->isStandardFilename($short),'Format classification identifies the exact supplied standard name and the short Clover name correctly');
$stalePage=$service->scan($admin->id,true);foreach($stalePage['entries'] as &$staleEntry)if($staleEntry['id']===$luc['id']){$staleEntry['status']='ready';$staleEntry['proposal']['target']='LB-LUC-RET7-BLACK-SINGLE.jpg';}unset($staleEntry);$store->save($stalePage);$writesBefore=$writer->writes;
$cleanup->process($processRequest($stalePage['token'],$luc['id'],$admin));
$check($writer->writes===$writesBefore && str_contains(session('error'),'already uses the expected ID-suffixed format'),'A cached shortening action is rejected even if it presents a ready row');
try{$service->begin($stalePage['token'],$admin->id,[$luc['id']],true);throw new LogicException('Standard-name shortening accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422 && $writer->writes===$writesBefore,'The rename engine independently blocks standard-name shortening');}
foreach(glob($directory.'/*.json') as $path)unlink($path);rmdir($directory);
echo "Simple filename cleanup checks passed.\n";
