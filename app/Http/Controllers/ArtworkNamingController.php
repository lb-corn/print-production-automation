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
        $summary=$plan?ArtworkRenameStore::summary($plan):$store->latestSummary($actor);
        $catalog=[];
        if($plan)foreach($plan['catalog'] as $row){$profile=(new ArtworkMatcherService())->match($row,[]);if($profile['artwork_review']['suggested_filenames'])$catalog[]=[...$row,'color'=>$profile['color'],'type'=>$profile['design_type'],'series'=>$profile['series_code']];}
        $scope=[];
        foreach(config('services.google_drive.allowed_root_folders',[]) as $root)$scope[$root]=[];
        if($plan)foreach($plan['index'] as $entry) {
            if(($entry['kind']??'')!=='series_folder')continue;
            $parts=array_map('trim',explode('/',$entry['path']));
            if(count($parts)===2 && array_key_exists($parts[0],$scope))$scope[$parts[0]][]=['name'=>$entry['name'],'id'=>$entry['id']];
        }
        $reviewGroups=[];
        if($plan)foreach($plan['entries'] as $entry)if(in_array($entry['status'],['review','conflict'],true)) {
            $key=$entry['source_design']??$entry['id'];
            if(!isset($reviewGroups[$key]))$reviewGroups[$key]=['entry'=>$entry,'count'=>0];
            $reviewGroups[$key]['count']++;
        }
        return view('admin.artwork-names',compact('plan','catalog','scope','reviewGroups','summary'));
    }
    public function scan(Request $request)
    {
        $actor=$this->actor($request);
        try{$plan=app(ArtworkNamingService::class)->scan($actor);return redirect()->route('admin.artwork-names',['plan'=>$plan['token']]);}
        catch(\Throwable $e){report($e);return redirect()->route('admin.artwork-names')->with('error','Cannot scan Drive and the product catalog. Check service-account access and Shopify read_products access. No files were renamed.');}
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
