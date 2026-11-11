<?php
// Both company layouts and full Admin flows against isolated DB/files and fake integrations.
require __DIR__.'/role-permissions.php';
use App\Services\{PartnerContext as Company,MaterialCatalog,GoogleDriveService,ShopifyService,ArtworkMatcherService,ArtworkNamingService,ArtworkRenameStore,GoogleDriveRenameService};
config(['services.google_drive.factory_root_id'=>null,'services.partners.vibe.drive_layout'=>'series','services.google_drive.reviewed_selections_enabled'=>true,'services.google_drive.rename_enabled'=>true]);
Illuminate\Support\Facades\Http::preventStrayRequests();
$base=storage_path('framework/cache/data/company-admin-test-'.bin2hex(random_bytes(6)));$bundles=[];
$app->instance(MaterialCatalog::class,new class extends MaterialCatalog {public function rows(bool $fresh=false):array{return Company::current()==='vibe'?['BEL'=>['name'=>'Believe','front'=>'Rave8','back'=>'Macarena','type'=>'single','template_size'=>'Small']]:['PSE'=>['name'=>'Pro Sniper Elite','front'=>'Rave9','back'=>'Viewmont','type'=>'single','template_size'=>'Normal']];}});
$bundle=function()use(&$bundles,$base){$company=Company::current();if(isset($bundles[$company]))return $bundles[$company];
    $drive=new class extends GoogleDriveService {
        public array $tree=[];
        public function __construct(){(new ReflectionProperty(GoogleDriveService::class,'allowedFolders'))->setValue($this,config('services.google_drive.allowed_root_folders'));}
        public function partnerRoot():string{return Company::current().'-root';}
        public function listChildren(string $folderId):array{return $this->tree[$folderId]??[];}
    };
    $folder=fn($id,$name)=>['id'=>$id,'name'=>$name,'mimeType'=>'application/vnd.google-apps.folder'];
    $vibe=$company==='vibe';$series=$company.'-series';$file=['id'=>$company.'-art','name'=>$vibe?'Gold Standard.jpg':'PSE-DUC-RED.jpg','mimeType'=>'image/jpeg','version'=>'1','modifiedTime'=>'t1','md5Checksum'=>'same','canRename'=>true];
    $drive->tree=[$company.'-root'=>$vibe?[$folder($series,'Believe'),$folder('backing','Backside Scan Materials'),$folder('unrelated','Unrelated')]:[$folder('group-b','GROUP B (Carpet)')],'group-b'=>[$folder($series,'PSE')],$series=>[$file,$folder('mockups','MOCKUPS')],'mockups'=>[[...$file,'id'=>'mockup']],'backing'=>[[...$file,'id'=>'scan']],'unrelated'=>[[...$file,'id'=>'unrelated']]];
    $shopify=new class extends ShopifyService {public array $rows=[];public function __construct(){}public function getArtworkCatalog():array{return $this->rows;}};
    $shopify->rows=[['catalog_key'=>hash('sha256','same-shopify-variant-id'),'sku'=>$vibe?'VB-BEL-GOLD-001':'2027-DUC-PSE-0001','product'=>$vibe?'Vibe Believe Gold Standard':'Lucky Bags 2027 Duck Camo','variant'=>$vibe?'Believe':'Pro Sniper Elite / Red']];
    $store=new ArtworkRenameStore($base.'/'.$company);$matcher=new ArtworkMatcherService($drive);$service=new ArtworkNamingService($drive,$shopify,$matcher,$store);
    return $bundles[$company]=compact('drive','shopify','store','matcher','service','series');
};
foreach([ArtworkRenameStore::class=>'store',ArtworkNamingService::class=>'service',GoogleDriveService::class=>'drive',ShopifyService::class=>'shopify',ArtworkMatcherService::class=>'matcher'] as $class=>$key)$app->bind($class,fn()=>$bundle()[$key]);
$writer=new class($bundle) extends GoogleDriveRenameService {
    public int $writes=0;public function __construct(private $bundle){}
    public function verify(array $expected):array{return [...$expected,'parents'=>[$expected['parent_id']],'etag'=>'test'];}
    public function rename(array $expected,string $target):array{$this->writes++;$drive=($this->bundle)()['drive'];foreach($drive->tree[$expected['parent_id']] as &$file)if($file['id']===$expected['id']){$file['name']=$target;$file['version']='2';$file['modifiedTime']='t2';}unset($file);return [...$expected,'name'=>$target,'version'=>'2','modifiedTime'=>'t2','parents'=>[$expected['parent_id']]];}
};$app->instance(GoogleDriveRenameService::class,$writer);$plans=[];
foreach(['lucky','vibe'] as $company)Company::run($company,function()use($company,$bundle,$admin,&$plans,$writer){
    $b=$bundle();$plan=$b['service']->scan($admin->id);$plans[$company]=$plan;
    check($plan['partner']===$company && count($plan['entries'])===1,$company.' scan includes only production series artwork, excluding mockups/backing/unrelated folders');
    $expected=$company==='vibe'?'Vibe / Believe':'GROUP B (Carpet) / PSE';check($plan['entries'][0]['folder']===$expected,$company.' respects its actual Drive layout');
    $row=$plan['catalog'][0];$id=$company.'-art';$mapping=['catalog_key'=>$row['catalog_key'],'side'=>'single','color'=>$company==='vibe'?'':'RED'];
    $plan=$b['service']->revise($plan['token'],$admin->id,[$id=>$mapping]);$b['service']->saveReviewedChoice($plan,$admin->id,$row['catalog_key'],[$id]);$b['matcher']->refreshReviewedSelections();
    check(($b['matcher']->match($row,$plan['index'])['artwork_review']['selection_source']??'')==='admin',$company.' manual artwork choice is remembered and available for order matching');
    $response=(new App\Http\Controllers\ArtworkNamingController)->syncSources(requestFor($admin,'admin.data-sync','POST'));
    parse_str(parse_url($response->getTargetUrl(),PHP_URL_QUERY),$query);
    check($query['partner']===$company && $b['store']->load($query['plan'],$admin->id,true)['partner']===$company,$company.' catalog sync redirect retains a readable company plan');
    $request=requestFor($admin,'admin.settings','GET',['section'=>'filenames']);$data=(new App\Http\Controllers\FilenameCleanupController)->index($request)->getData();
    check(count($data['filenameEntries'])===1,$company.' filename cleanup discovers its nonstandard artwork');
    $entry=$data['filenameEntries'][0];$blocked=App\Models\PrintBatch::create(['batch_id'=>$company.'-protected','status'=>'QUEUED','order_count'=>1,'artwork_file_count'=>1,'total_yards'=>.143,'payload'=>['orders'=>[['line_item_id'=>$company.'-line','artworks'=>[['drive_file_id'=>$id]]]]],'created_by'=>$admin->id]);
    $before=$writer->writes;$process=requestFor($admin,'admin.filename-cleanup.process','POST',['plan'=>$data['plan']['token'],'file_id'=>$id]);(new App\Http\Controllers\FilenameCleanupController)->process($process);
    check($writer->writes===$before,$company.' batch protection prevents a filename write');$blocked->delete();
    $data=(new App\Http\Controllers\FilenameCleanupController)->index($request)->getData();$process=requestFor($admin,'admin.filename-cleanup.process','POST',['plan'=>$data['plan']['token'],'file_id'=>$id]);$response=(new App\Http\Controllers\FilenameCleanupController)->process($process);
    check($writer->writes===$before+1 && str_contains($response->getTargetUrl(),'partner='.$company),$company.' one-click filename cleanup keeps the company and writes only its chosen file');
});
$middleware=new App\Http\Middleware\PartnerScope;
$middleware->handle(requestFor($admin,'admin.settings','GET',['section'=>'sync','partner'=>'vibe']),fn()=>'ok');
$middleware->handle(requestFor($admin,'admin.settings','GET',['section'=>'sync','plan'=>$plans['vibe']['token']]),function()use($admin,$plans){
    check(Company::current()==='vibe','An Admin URL without company restores the last permitted Admin selection');
    $response=(new App\Http\Controllers\AdminSettingsController)->index(requestFor($admin,'admin.settings','GET',['section'=>'sync','plan'=>$plans['vibe']['token']]));check(!$response instanceof Illuminate\Http\RedirectResponse,'Vibe plan links no longer look up Lucky storage');
});
Company::run('vibe',function()use($admin,$manager,$bundle,$plans){
    $response=(new App\Http\Controllers\AdminSettingsController)->index(requestFor($admin,'admin.settings','GET',['section'=>'sync','plan'=>$plans['lucky']['token']]));
    check($response->isRedirect(route('admin.settings',['section'=>'sync','partner'=>'vibe'])),'Wrong-company or unavailable previews return to the selected company instead of a blank 404');
    try{$bundle()['store']->load($plans['vibe']['token'],$manager->id,true);throw new LogicException('Other actor allowed');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){check($e->getStatusCode()===403,'Plan actor authorization remains enforced');}
});
echo "Company Admin flow checks passed.\n";
