<?php
// Network-free Drive API pagination and manufacturing-root allowlist regression.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['services.google_drive.factory_root_id'=>null]);
use App\Services\GoogleDriveService;
use Google\Client;
use Google\Service\Drive;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
$requests=[];
$mock=new MockHandler([
    new Response(200,[],json_encode(['nextPageToken'=>'page-two','files'=>[
        ['id'=>'a','name'=>'(GROUP A) Non Carpet','mimeType'=>'application/vnd.google-apps.folder'],
        ['id'=>'other','name'=>'Other','mimeType'=>'application/vnd.google-apps.folder'],
    ]])),
    new Response(200,[],json_encode(['files'=>[
        ['id'=>'b','name'=>'GROUP B (Carpet)','mimeType'=>'application/vnd.google-apps.folder'],
        ['id'=>'fake','name'=>'GROUP B (Carpet)','mimeType'=>'image/png'],
    ]])),
]);
$stack=HandlerStack::create($mock);
$stack->push(GuzzleHttp\Middleware::history($requests));
$client=new Client();
$client->setHttpClient(new HttpClient(['handler'=>$stack]));
$service=(new ReflectionClass(GoogleDriveService::class))->newInstanceWithoutConstructor();
foreach (['drive'=>new Drive($client),'rootId'=>'approved-root','allowedFolders'=>['(GROUP A) Non Carpet','GROUP B (Carpet)']] as $name=>$value) {
    (new ReflectionProperty(GoogleDriveService::class,$name))->setValue($service,$value);
}
$roots=$service->manufacturingRoots();
if (array_column($roots,'id')!==['a','b'] || count($requests)!==2) throw new RuntimeException('Root allowlist or pagination failed');
parse_str($requests[1]['request']->getUri()->getQuery(),$query);
if (($query['pageToken']??'')!=='page-two' || !str_contains($query['q'], "'approved-root' in parents") || ($query['includeItemsFromAllDrives']??'')!=='true') throw new RuntimeException('Drive pagination/shared-drive query changed');
echo "PASS: Paginated Drive listing retains parent scoping, shared-drive support and approved Group A / B allowlist\n";
