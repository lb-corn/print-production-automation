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
        $catalogSearch=$request->validate(['artwork_search'=>'sometimes|nullable|string|max:255'])['artwork_search']??'';
        $loadError=null;
        $plan=$request->query('plan')?$store->load((string)$request->query('plan'),$actor,true):$store->latestPreview($actor);
        if(!$plan && !$request->query('plan')) {
            try{$plan=app(ArtworkNamingService::class)->scan($actor);}
            catch(\Throwable $e){report($e);$loadError='Artwork could not load automatically. Check Drive access and the Active All Bags collection, then retry Check files.';}
        }
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
                $context=(new ArtworkMatcherService())->match($row,$plan['index']);
                if(($context['artwork_review']['selection_source']??'')==='admin'){$complete=true;$ambiguous=false;}
                elseif(($context['artwork_review']['selection_source']??'')==='invalidated_admin'){$complete=false;$ambiguous=false;}
                $identity=(new ArtworkMatcherService())->designIdentity($row['product']);
                $skuConflict=false;foreach($catalog as $other)if($other['sku']===$row['sku'] && (new ArtworkMatcherService())->designIdentity($other['product'])!==$identity)$skuConflict=true;
                $catalogStatus[]=[...$row,'sku_conflict'=>$skuConflict,'expected_folder'=>$context['artwork_review']['expected_folder'],'folder_url'=>$context['artwork_review']['folder_url'],'status'=>$ambiguous?'ambiguous':($complete?'matched':'missing')];
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
            if(!in_array(strtolower(pathinfo($entry['source']['name'],PATHINFO_EXTENSION)),['jpg','jpeg','png','tif','tiff','webp','psd','pdf'],true))continue;
            $folderName=trim(substr($entry['folder'],strrpos($entry['folder'],'/')+1));
            $attributes=$entry['source_attributes']??(new ArtworkMatcherService())->sourceAttributes($entry['source'],$folderName);
            if(!$attributes)continue;
            $entry['source_attributes']=$attributes;
            $reviewGroups[$entry['id']]=['entry'=>$entry,'count'=>1];
        }
        return view('admin.artwork-names',compact('plan','catalog','scope','reviewGroups','summary','catalogStatus','visibleEntries','loadError','catalogSearch'));
    }
    public function scan(Request $request)
    {
        $actor=$this->actor($request);
        $search=$request->validate(['artwork_search'=>'sometimes|nullable|string|max:255'])['artwork_search']??'';
        try{$plan=app(ArtworkNamingService::class)->scan($actor);}
        catch(\Throwable $e){report($e);return redirect()->route('admin.artwork-names')->with('error','Cannot load artwork from All Bags and Drive. Check that exactly one All Bags collection exists, Shopify read_products access, and Drive service-account access. No files were renamed.');}
        $response=redirect()->route('admin.settings',['section'=>'artwork','plan'=>$plan['token'],'artwork_search'=>$search]);
        try {
            $counts=app(\App\Services\ArtworkRematchService::class)->rematch($plan['index']);
            return $response->with('ok',sprintf('Current Drive files checked and unbatched orders refreshed: %d matched · %d missing · %d ambiguous · %d protected. Review artwork in Artwork Verification before approval. No files were renamed.',$counts['matched'],$counts['missing'],$counts['ambiguous'],$counts['skipped']));
        } catch(\App\Exceptions\ArtworkSchemaNotReady $e) {
            return $response->with('warning','Current Drive files checked, but orders could not refresh: '.$e->getMessage());
        } catch(\Throwable $e) {
            report($e);
            return $response->with('error','Current Drive files checked, but order matches could not refresh. Run Rematch Artwork and check the integration logs if it fails. No files were renamed.');
        }
    }
    public function revise(Request $request)
    {
        $actor=$this->actor($request);
        try {
            if($request->boolean('pair_mode')) {
                $data=$request->validate(['plan'=>'required|string|size:48','catalog_key'=>'required|string|size:64','front_file_id'=>'required|string|max:200','back_file_id'=>'required|string|max:200|different:front_file_id','reuse'=>'sometimes|boolean','color_correction_confirmed'=>'sometimes|accepted']);
                $plan=app(ArtworkNamingService::class)->revisePair($data['plan'],$actor,$data['catalog_key'],$data['front_file_id'],$data['back_file_id'],(bool)($data['reuse']??false),$request->boolean('color_correction_confirmed'));
                return $this->finishChoice($plan,$actor,$data['catalog_key'],[$data['front_file_id'],$data['back_file_id']]);
            }
            $data=$request->validate(['plan'=>'required|string|size:48','file_id'=>'required|string|max:200','catalog_key'=>'required|string|size:64',
                'side'=>'required|in:single,front,back','color'=>'nullable|string|max:40','reuse'=>'sometimes|boolean','color_correction_confirmed'=>'sometimes|accepted']);
            $plan=app(ArtworkNamingService::class)->revise($data['plan'],$actor,[$data['file_id']=>['catalog_key'=>$data['catalog_key'],'side'=>$data['side'],'color'=>$data['color']??'','color_correction_confirmed'=>$request->boolean('color_correction_confirmed')]],(bool)($data['reuse']??false));
            $entry=collect($plan['entries'])->firstWhere('id',$data['file_id']);
            if(($entry['status']??'review')==='review')return redirect()->route('admin.artwork-names',['plan'=>$plan['token']])->with('error','This mapping does not match the folder, SKU, color or print side. Check it and update the preview.');
            return $this->finishChoice($plan,$actor,$data['catalog_key'],[$data['file_id']]);
        } catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            if(!in_array($e->getStatusCode(),[409,422],true))throw $e;
            return redirect()->route('admin.artwork-names',['plan'=>$request->input('plan')])->with('error',$e->getMessage());
        }
    }
    private function finishChoice(array $plan,int $actor,string $catalogKey,array $ids)
    {
        if(config('services.google_drive.reviewed_selections_enabled',true)) {
            app(ArtworkNamingService::class)->saveReviewedChoice($plan,$actor,$catalogKey,$ids);
            try { app(\App\Services\ArtworkRematchService::class)->rematch(); }
            catch(\App\Exceptions\ArtworkSchemaNotReady $e) { return redirect()->route('admin.artwork-names',['plan'=>$plan['token']])->with('warning','Artwork choice saved. Orders can sync, but the server needs the artwork_review migration before refreshing approval metadata and printing.'); }
            catch(\Throwable $e) { report($e);return redirect()->route('admin.artwork-names',['plan'=>$plan['token']])->with('error','Your artwork choice was saved, but order matches could not refresh. Run Rematch Artwork.'); }
        }
        return redirect()->route('admin.artwork-names',['plan'=>$plan['token']])->with('ok','Artwork choice saved and unbatched matches refreshed. Print approval is still required. Apply any filename changes separately.');
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
