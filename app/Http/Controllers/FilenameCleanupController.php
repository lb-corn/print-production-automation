<?php
namespace App\Http\Controllers;

use App\Services\{ArtworkNamingService,ArtworkRenameStore,ArtworkMatcherService};
use Illuminate\Http\Request;

class FilenameCleanupController extends Controller
{
    private function actor(Request $request): int { abort_unless($request->user()?->role==='admin',403);return $request->user()->id; }
    public function index(Request $request)
    {
        $actor=$this->actor($request);$plan=null;$entries=[];$loadError=null;
        try {
            $plan=app(ArtworkNamingService::class)->scan($actor,true);
            $preferred=app(ArtworkRenameStore::class)->preferredFilenames();
            $matcher=app(ArtworkMatcherService::class);
            $entries=array_values(array_filter($plan['entries'],fn($entry)=>!$matcher->isStandardFilename($entry['source']) && !empty($entry['proposal']) && $entry['source']['name']!==$entry['proposal']['target'] && !(($preferred[$entry['id']]['policy']??null)===ArtworkNamingService::POLICY && ($preferred[$entry['id']]['fingerprint']??null)===ArtworkMatcherService::artworkFingerprint($entry['source']))));
        } catch(\Throwable $e) { report($e);$loadError='Could not load filename suggestions. Check Shopify and Drive access, then refresh the list.'; }
        return view('admin.settings',['section'=>'filenames','plan'=>$plan,'filenameEntries'=>$entries,'loadError'=>$loadError]);
    }
    public function process(Request $request)
    {
        $actor=$this->actor($request);
        $data=$request->validate(['plan'=>'required|string|size:48','file_id'=>'required|string|max:200','suggested_name'=>'sometimes|required|string|max:255']);
        $back=redirect()->route('admin.settings',['section'=>'filenames']);
        try {
            $plan=app(ArtworkRenameStore::class)->load($data['plan'],$actor);
            abort_unless(!empty($plan['standardize']),422,'Refresh the filename list before processing a suggestion.');
            $entry=collect($plan['entries'])->firstWhere('id',$data['file_id']);
            abort_unless($entry && $entry['status']==='ready',422,'This file cannot be renamed. Refresh the list to see its current status.');
            abort_if(app(ArtworkMatcherService::class)->isStandardFilename($entry['source']),422,'This file already uses the expected ID-suffixed format. No rename is needed. Refresh the list.');
            if(isset($data['suggested_name']) && $data['suggested_name']!==$entry['proposal']['target']) {
                app(ArtworkNamingService::class)->setSuggestedName($data['plan'],$actor,$data['file_id'],$data['suggested_name']);
                $entry['proposal']['target']=trim($data['suggested_name']);
            }
            app(ArtworkNamingService::class)->begin($data['plan'],$actor,[$data['file_id']],true);
        } catch(\Throwable $e) {
            report($e);
            $message=$e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface || $e instanceof \App\Exceptions\ArtworkSchemaNotReady?$e->getMessage():'Could not check the current file. Refresh the list and try again.';
            return $back->with('error',$message.' No files were renamed.');
        }
        try { $result=app(ArtworkNamingService::class)->step($data['plan'],$actor); }
        catch(\Throwable $e) { report($e);return $back->with('warning','The rename result could not be confirmed. Check the file in Drive before trying again.'); }
        if($result['status']!=='complete')return $back->with('warning',$result['warning']??'The rename was not confirmed. Check Drive and refresh the list before trying again.');
        if(!empty($result['warning']))return $back->with('warning',$result['warning']);
        return $back->with('ok','Renamed '.$entry['source']['name'].' → '.$entry['proposal']['target'].'. Artwork matches refreshed. Affected unbatched orders need artwork approval again.');
    }
}
