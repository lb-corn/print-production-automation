<?php
namespace App\Http\Controllers;

use App\Services\{ArtworkNamingService,ArtworkRenameStore,ArtworkMatcherService};
use Illuminate\Http\Request;

class ArtworkNamingController extends Controller
{
    private function actor(Request $request): int { abort_unless($request->user()?->role==='admin',403);return $request->user()->id; }
    public function index(Request $request,ArtworkRenameStore $store)
    {
        $actor=$this->actor($request);
        $plan=$request->query('plan')?$store->load((string)$request->query('plan'),$actor,true):null;
        $currentPlan=$plan && ($plan['naming_policy']??null)===ArtworkNamingService::POLICY;
        $summary=$currentPlan?ArtworkRenameStore::summary($plan):$store->latestSummary($actor);
        $catalog=[];
        if($currentPlan)foreach($plan['catalog'] as $row){$profile=(new ArtworkMatcherService())->match($row,[]);if($profile['artwork_review']['suggested_filenames'])$catalog[]=[...$row,'color'=>$profile['color'],'type'=>$profile['design_type'],'series'=>$profile['series_code']];}
        $catalogStatus=[];$visibleEntries=[];
        if($currentPlan) {
            $visibleEntries=array_values(array_filter($plan['entries'],fn($entry)=>!empty($entry['proposal']) || isset($plan['mappings'][$entry['id']])));
            foreach($catalog as $row) {
                $files=array_values(array_filter($visibleEntries,fn($entry)=>in_array($row['catalog_key'],$entry['proposal']['catalog_keys']??[$entry['proposal']['catalog_key']??null],true)));
                $sides=array_count_values(array_column(array_column($files,'proposal'),'side'));
                $required=$row['type']==='double'?['front','back']:['single'];
                $ambiguous=false;$complete=true;
                foreach($required as $side){if(($sides[$side]??0)>1)$ambiguous=true;if(!($sides[$side]??0))$complete=false;}
                $context=(new ArtworkMatcherService())->match($row,array_values(array_filter($plan['index'],fn($entry)=>($entry['kind']??'')==='series_folder')));
                $catalogStatus[]=[...$row,'expected_folder'=>$context['artwork_review']['expected_folder'],'folder_url'=>$context['artwork_review']['folder_url'],'status'=>$ambiguous?'ambiguous':($complete?'matched':'missing')];
            }
        }
        $scope=[];
        foreach(config('services.google_drive.allowed_root_folders',[]) as $root)$scope[$root]=[];
        if($plan)foreach($plan['index'] as $entry) {
            if(($entry['kind']??'')!=='series_folder')continue;
            $parts=array_map('trim',explode('/',$entry['path']));
            if(count($parts)===2 && array_key_exists($parts[0],$scope))$scope[$parts[0]][]=['name'=>$entry['name'],'id'=>$entry['id']];
        }
        // Candidates include already-standard artwork; no file is selected automatically.
        $reviewGroups=[];
        if($currentPlan)foreach($plan['entries'] as $entry) {
            if($entry['status']==='locked' || !in_array(strtolower(pathinfo($entry['source']['name'],PATHINFO_EXTENSION)),['jpg','jpeg','png','tif','tiff','webp','psd','pdf'],true))continue;
            $folderName=trim(substr($entry['folder'],strrpos($entry['folder'],'/')+1));
            $attributes=$entry['source_attributes']??(new ArtworkMatcherService())->sourceAttributes($entry['source'],$folderName);
            if(!$attributes)continue;
            $entry['source_attributes']=$attributes;
            $reviewGroups[$entry['id']]=['entry'=>$entry,'count'=>1];
        }
        return view('admin.artwork-names',compact('plan','catalog','scope','reviewGroups','summary','catalogStatus','visibleEntries'));
    }
    public function scan(Request $request)
    {
        $actor=$this->actor($request);
        try{$plan=app(ArtworkNamingService::class)->scan($actor);return redirect()->route('admin.artwork-names',['plan'=>$plan['token']]);}
        catch(\Throwable $e){report($e);return redirect()->route('admin.artwork-names')->with('error','Cannot load artwork from All Bags and Drive. Check that exactly one All Bags collection exists, Shopify read_products access, and Drive service-account access. No files were renamed.');}
    }
    public function revise(Request $request)
    {
        $actor=$this->actor($request);
        $data=$request->validate(['plan'=>'required|string|size:48','file_id'=>'required|string|max:200','catalog_key'=>'required|string|size:64',
            'side'=>'required|in:single,front,back','color'=>'nullable|string|max:40','reuse'=>'sometimes|boolean']);
        $plan=app(ArtworkNamingService::class)->revise($data['plan'],$actor,[$data['file_id']=>['catalog_key'=>$data['catalog_key'],'side'=>$data['side'],'color'=>$data['color']??'']],(bool)($data['reuse']??false));
        $entry=collect($plan['entries'])->firstWhere('id',$data['file_id']);
        if(($entry['status']??'review')==='review')return redirect()->route('admin.artwork-names',['plan'=>$plan['token']])->with('error','This mapping does not match the folder, SKU, color or print side. Check it and update the preview.');
        return redirect()->route('admin.artwork-names',['plan'=>$plan['token']]);
    }
    public function begin(Request $request)
    {
        $actor=$this->actor($request);
        $data=$request->validate(['plan'=>'required|string|size:48','ids'=>'required|array|min:1|max:500','ids.*'=>'required|string|max:200|distinct',
            'password'=>'required|current_password','confirmation'=>'required|in:RENAME','reviewed'=>'required|accepted','refresh'=>'sometimes|boolean']);
        $plan=app(ArtworkNamingService::class)->begin($data['plan'],$actor,$data['ids'],(bool)($data['refresh']??false));
        return response()->json($this->progress($plan));
    }
    public function step(Request $request)
    {
        $actor=$this->actor($request);$data=$request->validate(['plan'=>'required|string|size:48']);
        $plan=app(ArtworkNamingService::class)->step($data['plan'],$actor);
        return response()->json($this->progress($plan));
    }
    private function progress(array $plan): array
    {
        return ['status'=>$plan['status'],'renamed'=>$plan['renamed']??0,'remaining'=>count($plan['pending']??[]),'warning'=>$plan['warning']??null,
            'url'=>route('admin.artwork-names',['plan'=>$plan['token']])];
    }
}
