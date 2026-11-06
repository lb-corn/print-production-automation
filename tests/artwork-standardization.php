<?php
// Isolated regression fixtures, including the existing guarded-rename suite.
use App\Services\{ArtworkNamingService,ArtworkMatcherService,GoogleDriveRenameService,ArtworkRematchService,ProductionService};
use App\Models\{ProductionItem,PrintBatch};
require __DIR__.'/artwork-naming.php';
$standardName=fn($stem,$id,$extension='jpg')=>$stem.'-ID-'.strtoupper(substr(hash('sha256',$id),0,12)).'.'.$extension;
auth()->setUser($admin);
config(['services.google_drive.reviewed_selections_enabled'=>true,'services.google_drive.rename_enabled'=>true]);
$matcher->refreshReviewedSelections();
$writer=new class($drive) extends GoogleDriveRenameService {
    public int $writes=0;public bool $permission=true;public ?int $failAfter=null;
    public function __construct(private $drive){}
    public function verify(array $expected):array { if(!$this->permission)throw new RuntimeException('Editor access required');return $expected; }
    public function rename(array $expected,string $target):array {
        $this->verify($expected);
        if($this->failAfter!==null && $this->writes>=$this->failAfter)throw new RuntimeException('Uncertain remote result');
        $this->writes++;
        foreach($this->drive->index as &$file)if(($file['id']??null)===$expected['id']) {
            $file['name']=$target;$file['path']=substr($file['path'],0,strrpos($file['path'],' / ')+3).$target;
            $file['version']=(string)((int)$file['version']+1);$file['modifiedTime']='changed-'.$file['version'];
            $after=[...$file,'parents'=>[$file['parent_id']]];
        }
        unset($file);return $after;
    }
};
$app->instance(GoogleDriveRenameService::class,$writer);
$app->instance(ArtworkRematchService::class,new ArtworkRematchService($drive,$matcher));
$retro=[...$row,'catalog_key'=>hash('sha256','optional-retro'),'product'=>'Lucky Bags 2027 Retro 7','sku'=>'2027-RET7-CLV-0001','variant'=>'Clovers'];
$folder=['kind'=>'series_folder','id'=>'optional-clv','name'=>'Clover','path'=>'GROUP B (Carpet) / Clover'];
$file=[...$source('optional-retro','CLV-RETRO 7 GRUNGE.JPEG'),'parent_id'=>$folder['id'],'path'=>$folder['path'].' / CLV-RETRO 7 GRUNGE.JPEG','canRename'=>true];
$shopify->rows=[$retro];$drive->index=[$folder,$file];
$normal=$service->scan($admin->id);$normal=$service->revise($normal['token'],$admin->id,[$file['id']=>['catalog_key'=>$retro['catalog_key'],'side'=>'single','color'=>'']],false);
$service->saveReviewedChoice($normal,$admin->id,$retro['catalog_key'],[$file['id']]);
$item=ProductionItem::create(['line_item_id'=>'optional-standard-order','order_number'=>'#4259','quantity'=>1,...array_intersect_key($retro,array_flip(['sku','product','variant'])),...$matcher->match($retro,$drive->index)]);
app(ProductionService::class)->verify($item,'CORRECT',$admin->id);
$normal=$service->scan($admin->id);$check($normal['entries'][0]['status']==='standard' && $normal['entries'][0]['proposal']['target']===$file['name'],'Ordinary matching keeps a reviewed working filename unchanged');
$before=$item->fresh('decision')->toJson();$batchesBefore=PrintBatch::all()->toJson();$selectionsBefore=$store->selections();
$optional=$service->scan($admin->id,true);$entry=$optional['entries'][0];
$retroTarget=$standardName('LB-CLV-RET7-SINGLE',$file['id'],'JPEG');
$check($optional['standardize'] && $entry['status']==='ready' && $entry['proposal']['target']===$retroTarget,'Explicit optional preview offers the ID-suffixed code/side name and preserves extension');
$check($writer->writes===0 && $item->fresh('decision')->toJson()===$before && PrintBatch::all()->toJson()===$batchesBefore && $store->selections()===$selectionsBefore,'Preparing optional names changes no Drive file, order, approval, batch or reviewed choice');
$check(empty($store->latestPreview($admin->id)['standardize']),'Regular artwork navigation never implicitly restores an optional standardization preview');
$request=Illuminate\Http\Request::create('/admin/settings','GET',['plan'=>$optional['token']]);$request->setUserResolver(fn()=>$admin);
$html=$controller->index($request,$store)->render();$dom=new DOMDocument();@$dom->loadHTML($html,LIBXML_NONET);$xpath=new DOMXPath($dom);
$check($xpath->query('//input[@name="ids[]"]')->length===0 && str_contains($html,'Filename cleanup'),'Artwork UI has no legacy standardization preview or bulk selections');
// A destination appearing after preparation must reject the run before any write.
$collision=[...$file,'id'=>'canonical-collision','name'=>$entry['proposal']['target'],'path'=>$folder['path'].' / '.$entry['proposal']['target']];$drive->index[]=$collision;
try{$service->begin($optional['token'],$admin->id,[$file['id']],false);throw new LogicException('Collision accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422 && $writer->writes===0,'A canonical destination collision blocks optional renaming without adding IDs');}
array_pop($drive->index);
// A newly queued reference is protected even when the preview was prepared earlier.
$guard=PrintBatch::create(['batch_id'=>'optional-guard','status'=>'QUEUED','order_count'=>1,'artwork_file_count'=>1,'payload'=>['orders'=>[['line_item_id'=>'guard-only','artworks'=>[['drive_file_id'=>$file['id']]]]]]]);
foreach(['QUEUED','CLAIMED','PRINTED','FAILED'] as $state) {
    $guard->update(['status'=>$state]);$protected=$service->scan($admin->id,true);
    $check($protected['entries'][0]['status']==='locked','Optional standard names preserve '.$state.' batch protection');
}
try{$service->begin($optional['token'],$admin->id,[$file['id']],false);throw new LogicException('Batched file accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422 && $writer->writes===0 && $item->fresh('decision')->toJson()===$before,'Batch entry after preview blocks application and preserves existing approval');}
$guard->delete();
// Permission failure is detected before changing the portal selection or approval.
$writer->permission=false;$active=$service->begin($optional['token'],$admin->id,[$file['id']],false);$stopped=$service->step($active['token'],$admin->id);
$check($stopped['status']==='stopped' && $writer->writes===0 && $item->fresh('decision')->toJson()===$before,'Missing Drive write permission leaves a working order and its approval intact');
$writer->permission=true;$optional=$service->scan($admin->id,true);$active=$service->begin($optional['token'],$admin->id,[$file['id']],false);
$check($active['refresh']===true,'Optional standardization requires automatic match refresh even when the caller passes false');
$done=$service->step($active['token'],$admin->id);$item=$item->fresh('decision');
$check($done['status']==='complete' && $writer->writes===1 && $item->match_status==='REVIEW' && $item->matches[0]['name']===$retroTarget && !$item->decision,'Successful optional rename updates Drive and the order, retains matchability and requires fresh approval');
$choice=$store->selections()[$matcher->selectionKey($retro)];
$check(ArtworkMatcherService::artworkFingerprint($choice['files']['single']['source'])===ArtworkMatcherService::artworkFingerprint($drive->index[1]) && $matcher->match($retro,$drive->index)['artwork_review']['selection_source']==='admin','Verified rename advances the exact-file fingerprint so future rematches keep the reviewed choice');
$check(PrintBatch::all()->toJson()===$batchesBefore && $done['events'][0]['old_name']===$file['name'] && $done['events'][0]['new_name']===$retroTarget,'Optional operation preserves all existing queues and records original/new names in the audit');
$check($service->scan($admin->id,true)['entries'][0]['status']==='standard' && $service->scan($admin->id)['entries'][0]['status']==='standard','Both regular and optional scans leave the newly canonical file unchanged');
// Reviewed front/back choices keep their exact IDs across a partial operation.
$double=[...$retro,'catalog_key'=>hash('sha256','optional-double'),'sku'=>'2027-SOG-SRF-0001','product'=>'Lucky Bags 2027 Surefire OG','variant'=>'Surefire / Black'];
$doubleFolder=['kind'=>'series_folder','id'=>'optional-srf','name'=>'Surefire','path'=>'(GROUP A) Non Carpet / Surefire'];
$pair=[];foreach(['front','back'] as $side){$name='SRF-0G 2027-MIDNIGHT-'.strtoupper($side).'.jpg';$pair[]=[...$source('optional-'.$side,$name),'parent_id'=>$doubleFolder['id'],'path'=>$doubleFolder['path'].' / '.$name,'canRename'=>true];}
$shopify->rows=[$double];$drive->index=[$doubleFolder,...$pair];$pairPlan=$service->scan($admin->id);
$pairPlan=$service->revisePair($pairPlan['token'],$admin->id,$double['catalog_key'],$pair[0]['id'],$pair[1]['id']);$service->saveReviewedChoice($pairPlan,$admin->id,$double['catalog_key'],array_column($pair,'id'));
$pairItem=ProductionItem::create(['line_item_id'=>'optional-double-order','order_number'=>'#4254','quantity'=>1,...array_intersect_key($double,array_flip(['sku','product','variant'])),...$matcher->match($double,$drive->index)]);
app(ProductionService::class)->verify($pairItem,'CORRECT',$admin->id);$pairPlan=$service->scan($admin->id,true);
$pairTargets=[$standardName('LB-SRF-SOG-BLACK-FRONT',$pair[0]['id']),$standardName('LB-SRF-SOG-BLACK-BACK',$pair[1]['id'])];
$check(array_column(array_column($pairPlan['entries'],'proposal'),'target')===$pairTargets,'Optional preview uses the reviewed variant color, required FRONT/BACK and stable file ID suffix');
$drive->index[1]['version']='99';
try{$service->begin($pairPlan['token'],$admin->id,array_column($pair,'id'),true);throw new LogicException('Changed file accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Changed source metadata invalidates the optional preview before any Drive write');}
$drive->index[1]=$pair[0];
$writer->failAfter=$writer->writes+1;$active=$service->begin($pairPlan['token'],$admin->id,array_column($pair,'id'),true);$partial=$service->step($active['token'],$admin->id);
$check($partial['status']==='stopped' && $partial['renamed']===1 && !$pairItem->fresh('decision')->decision && !$pairItem->fresh()->matches,'Uncertain second-side rename stops the operation and never retains stale print approval');
$check($partial['events'][0]['status']==='renamed' && $partial['events'][1]['status']==='attempting','Partial audit distinguishes confirmed changes from the uncertain remote attempt');
$writer->failAfter=null;$pairPlan=$service->scan($admin->id,true);
$check(array_column($pairPlan['entries'],'status')===['standard','ready'],'A fresh preview after partial failure offers only the still-legacy side');
$active=$service->begin($pairPlan['token'],$admin->id,[$pair[1]['id']],true);$finished=$service->step($active['token'],$admin->id);$pairItem=$pairItem->fresh('decision');
$check($finished['status']==='complete' && array_column($pairItem->matches,'id')===array_column($pair,'id') && array_column($pairItem->matches,'name')===$pairTargets && !$pairItem->decision,'After an explicit fresh review, both renamed sides retain their IDs and reappear together without automatic approval');
$check(PrintBatch::all()->toJson()===$batchesBefore,'Front/back standardization never modifies another print queue');
$legacyId='legacy-compatible';$legacy=[...$file,'id'=>$legacyId,'name'=>'LB-CLV-RET7-SINGLE-ID-'.strtoupper(substr(hash('sha256',$legacyId),0,12)).'.JPEG'];
$check($matcher->namingCandidate($retro,$legacy,'Clover')['target']===$legacy['name'] && $matcher->namingCandidate($retro,$legacy,'Clover',null,true)['target']===$legacy['name'],'Correct ID-suffixed names remain unchanged in both matching and cleanup');
$plainClover=[...$file,'name'=>'LB-CLV-RET7-SINGLE.jpg'];
$check($matcher->namingCandidate($retro,$plainClover,'Clover',null,true)['target']===$standardName('LB-CLV-RET7-SINGLE',$plainClover['id']),'The reported short Clover filename receives a stable ID suffix without inventing BLACK or changing CLV to LUC');
$luciano=[...$retro,'sku'=>'2027-RET7-LUC-0001','variant'=>'Luciano'];$lucianoFile=[...$file,'id'=>'10W9EHnm4xOr4OJ4jiHQuCo_4NtOSpSIG','name'=>'LB-LUC-RET7-BLACK-SINGLE-ID-A170B0A272A7.jpg'];
$check($matcher->namingCandidate($luciano,$lucianoFile,'Luciano',null,true)['target']===$lucianoFile['name'],'The supplied Luciano ID-suffixed filename is already standard and is not suggested for shortening');
$writesBefore=$writer->writes;$ordersBefore=ProductionItem::all()->toJson();
$request=Illuminate\Http\Request::create('/admin/artwork-names/standardize','POST');$request->setUserResolver(fn()=>$admin);$response=$controller->standardize($request);parse_str(parse_url($response->getTargetUrl(),PHP_URL_QUERY),$query);
$check(($query['section']??null)==='filenames' && $writer->writes===$writesBefore && ProductionItem::all()->toJson()===$ordersBefore,'Legacy Prepare standard names action redirects to simple cleanup without mutations');
$request=Illuminate\Http\Request::create('/admin/artwork-names/standardize','POST');$request->setUserResolver(fn()=>$operator);
try{$controller->standardize($request);throw new LogicException('Operator prepared names');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===403,'Normal users cannot prepare optional filename changes');}
$route=app('router')->getRoutes()->getByName('admin.artwork-names.standardize');
$check(in_array('POST',$route->methods()) && in_array(App\Http\Middleware\RequireFeature::class,$route->gatherMiddleware()) && in_array(App\Http\Middleware\PortalWriteLock::class,$route->gatherMiddleware()),'Optional standard-name preparation is an authenticated serialized admin POST');
foreach(glob($directory.'/*.json') as $path)unlink($path);rmdir($directory);
echo "Optional artwork standardization checks passed.\n";
