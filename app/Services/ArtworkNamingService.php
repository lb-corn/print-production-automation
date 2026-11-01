<?php
namespace App\Services;

use App\Models\{PrintBatch,ProductionItem,VerificationDecision};
use Illuminate\Support\Facades\DB;

class ArtworkNamingService
{
    public function __construct(private GoogleDriveService $drive,private ShopifyService $shopify,
        private ArtworkMatcherService $matcher,private ArtworkRenameStore $store) {}

    public function scan(int $actor): array
    {
        $index=$this->drive->artworkIndex();$catalog=$this->shopify->getArtworkCatalog();
        return $this->store->create($actor,['index'=>$index,'catalog'=>$catalog,'catalog_digest'=>$this->catalogDigest($catalog),
            'entries'=>$this->build($index,$catalog,[], $this->lockedFiles()),'mappings'=>[]]);
    }
    public function revise(string $token,int $actor,array $mappings): array
    {
        $plan=$this->store->load($token,$actor);abort_unless($plan['status']==='preview',422,'This plan is already submitted. Scan again.');
        $entries=array_column($plan['entries'],null,'id');
        foreach($mappings as $id=>$mapping) {
            abort_unless(isset($entries[$id]) && $entries[$id]['status']!=='locked',422,'Choose an unbatched file from this preview.');
            if(!empty($mapping['catalog_key']))$plan['mappings'][$id]=$mapping;
        }
        $plan['entries']=$this->build($plan['index'],$plan['catalog'],$plan['mappings'],$this->lockedFiles());
        $this->store->save($plan);return $plan;
    }
    public function begin(string $token,int $actor,array $ids,bool $refresh): array
    {
        abort_unless(config('services.google_drive.rename_enabled',false),403,'Enable Drive renaming on the server before applying a plan.');
        ArtworkRematchService::ensureSchemaReady();
        $plan=$this->store->load($token,$actor);abort_unless($plan['status']==='preview',422,'This plan was already submitted. Scan again.');
        abort_if(!$ids || count($ids)!==count(array_unique($ids)),422,'Choose distinct ready files.');
        $entries=array_column($plan['entries'],null,'id');
        foreach($ids as $id)abort_unless(isset($entries[$id])&&$entries[$id]['status']==='ready',422,'Only ready files from this preview may be renamed.');
        $this->revalidate($plan,$ids);
        $plan['status']='applying';$plan['expires_at']=time()+7200;$plan['pending']=array_values($ids);$plan['renamed']=0;$plan['refresh']=$refresh;$plan['approved_ids']=array_values($ids);$plan['confirmed_at']=gmdate('c');
        $this->store->save($plan);return $plan;
    }
    /** Process small chunks so a single confirmation can safely handle a large folder. */
    public function step(string $token,int $actor): array
    {
        abort_unless(config('services.google_drive.rename_enabled',false),403);
        $plan=$this->store->load($token,$actor);abort_unless($plan['status']==='applying',422,'No active rename operation.');
        $ids=array_slice($plan['pending'],0,5);
        try {
            $current=$this->revalidate($plan,$ids,false);
            foreach($ids as $id)app(GoogleDriveRenameService::class)->verify($current[$id]);
            // Same item-lock order as batching. PortalWriteLock also serializes the whole HTTP request.
            DB::transaction(function()use(&$plan,$ids,$current){
                $items=ProductionItem::orderBy('id')->lockForUpdate()->get();$locked=$this->lockedFiles();$processed=app(ProductionService::class)->batchIndex(true);
                foreach($ids as $id)abort_if(isset($locked[$id]),422,'A file entered a batch. Scan again.');
                foreach($items as $item) {
                    if(isset($processed[$item->line_item_id]) || !array_intersect($ids,array_column($item->matches??[],'id')))continue;
                    // Invalidate before the first remote write; even uncertain network results cannot retain approval.
                    VerificationDecision::where('line_item_id',$item->line_item_id)->delete();
                    $item->fill(['matches'=>[],'match_status'=>'MISSING','confidence'=>null,'artwork_review'=>null])->save();
                }
            });
            foreach($ids as $id) {
                $entry=collect($plan['entries'])->firstWhere('id',$id);
                $plan['events'][]=['file_id'=>$id,'old_name'=>$current[$id]['name'],'new_name'=>$entry['proposal']['target'],'actor'=>$actor,'at'=>gmdate('c'),'status'=>'attempting'];
                $this->store->save($plan); // Durable before any metadata mutation; failures stop the operation.
                $after=app(GoogleDriveRenameService::class)->rename($current[$id],$entry['proposal']['target']);
                $event=array_key_last($plan['events']);$plan['events'][$event]['status']='renamed';$plan['events'][$event]['after']=$after;
                $plan['renamed']++;array_shift($plan['pending']);foreach($plan['entries'] as &$updated)if($updated['id']===$id)$updated['status']='renamed';unset($updated);$this->store->save($plan);
            }
            if(!$plan['pending']) {
                $plan['status']='complete';$plan['match_counts']=null;
                if($plan['refresh']) {
                    try{$plan['match_counts']=app(ArtworkRematchService::class)->rematch();}
                    catch(\Throwable $e){report($e);$plan['warning']='Names changed, but unbatched matches could not be refreshed. Run Rematch Artwork.';}
                }
            }
        } catch(\Throwable $e) {
            report($e);$plan['status']='stopped';
            $plan['warning']='Operation stopped. Check the audit and scan again; some names may have changed and no automatic retry will run.';
            if($e instanceof \RuntimeException && str_contains($e->getMessage(),'Editor access'))$plan['warning']=$e->getMessage().' No further files were attempted.';
        }
        $this->store->save($plan);return $plan;
    }
    private function revalidate(array $plan,array $ids,bool $checkCatalog=true): array
    {
        if($checkCatalog)abort_unless($this->catalogDigest($this->shopify->getArtworkCatalog())===$plan['catalog_digest'],422,'Shopify catalog changed. Scan again.');
        $index=$this->drive->artworkIndex();$locked=$this->lockedFiles();
        $current=array_column(array_filter($index,fn($f)=>($f['kind']??'')==='artwork'),null,'id');
        $entries=$this->build($index,$plan['catalog'],$plan['mappings'],$locked);$entries=array_column($entries,null,'id');
        $original=array_column($plan['entries'],null,'id');
        foreach($ids as $id) {
            abort_unless(isset($current[$id],$original[$id],$entries[$id]) && $entries[$id]['status']==='ready'
                && $entries[$id]['proposal']===$original[$id]['proposal'] && $this->fileDigest($current[$id])===$this->fileDigest($original[$id]['source']),422,'File, folder, mapping or destination changed. Scan again.');
        }
        return $current;
    }
    public function build(array $index,array $catalog,array $mappings,array $locked): array
    {
        $folders=array_column(array_filter($index,function($folder){
            if(($folder['kind']??'')!=='series_folder')return false;
            $parts=array_map('trim',explode('/',$folder['path']??''));
            return count($parts)===2 && in_array($parts[0],config('services.google_drive.allowed_root_folders',[]),true)
                && $parts[1]===($folder['name']??null) && ArtworkMatcherService::seriesForFolder($folder['name'])!==null;
        }),null,'id');
        $catalog=array_column($catalog,null,'catalog_key');$entries=[];$targets=[];
        foreach($index as $file) {
            if(($file['kind']??'')!=='artwork'||!isset($folders[$file['parent_id']??'']))continue;
            $folder=$folders[$file['parent_id']];
            $path=array_map('trim',explode('/',$file['path']??''));$folderPath=array_map('trim',explode('/',$folder['path']));
            if(count($path)!==3 || array_slice($path,0,2)!==$folderPath || $path[2]!==$file['name'])continue;
            $choices=[];
            $mapping=$mappings[$file['id']]??null;
            foreach($catalog as $row) {
                if($mapping && ($mapping['catalog_key']??null)!==$row['catalog_key'])continue;
                $candidate=$this->matcher->namingCandidate($row,$file,$folder['name'],$mapping);
                if(!$candidate)continue;
                // Reuse folder ambiguity detection from the matcher without choosing any file.
                $context=$this->matcher->match($row,array_values($folders));
                if($context['match_status']==='AMBIGUOUS')continue;
                $choices[]=$candidate;
            }
            $proposal=count($choices)===1?$choices[0]:null;
            $status=isset($locked[$file['id']])?'locked':(!$proposal?'review':($file['name']===$proposal['target']?'standard':'ready'));
            $entry=['id'=>$file['id'],'source'=>$file,'folder'=>$folder['path'],'status'=>$status,'proposal'=>$proposal,'candidate_count'=>count($choices)];
            $entries[]=$entry;
            if($proposal)$targets[$file['parent_id'].'/'.$this->stem($proposal['target'])][]=$file['id'];
        }
        foreach($entries as &$entry) {
            if(!$entry['proposal'] || $entry['status']==='locked')continue;
            $key=$entry['source']['parent_id'].'/'.$this->stem($entry['proposal']['target']);
            $collision=count($targets[$key]??[])>1;
            foreach($entries as $other)if($other['id']!==$entry['id'] && $other['source']['parent_id']===$entry['source']['parent_id']
                && $this->stem($other['source']['name'])===$this->stem($entry['proposal']['target']))$collision=true;
            if($collision)$entry['status']='conflict';
        }
        unset($entry);
        $designGroups=[];$variantGroups=[];
        foreach($entries as $entry)if($entry['proposal']) {
            $proposal=$entry['proposal'];
            $designGroups[$proposal['sku'].'/'.$proposal['color'].'/'.$proposal['side']][]=$entry['id'];
            $variantGroups[$proposal['catalog_key'].'/'.$proposal['side']][]=$entry['id'];
        }
        foreach($entries as &$entry)if($entry['proposal']) {
            $proposal=$entry['proposal'];
            $entry['artwork_duplicate']=count($designGroups[$proposal['sku'].'/'.$proposal['color'].'/'.$proposal['side']]??[])>1
                || count($variantGroups[$proposal['catalog_key'].'/'.$proposal['side']]??[])>1;
        }
        unset($entry);
        return $entries;
    }
    public function lockedFiles(): array
    {
        $ids=[];
        $walk=function($value,$key=null)use(&$walk,&$ids){
            if($key==='drive_file_id' && is_string($value))$ids[$value]=true;
            if($key==='drive_file_ids' && is_array($value))foreach($value as $id)if(is_string($id))$ids[$id]=true;
            if(is_array($value))foreach($value as $childKey=>$child)$walk($child,$childKey);
        };
        foreach(PrintBatch::all() as $batch)$walk($batch->payload);
        $processed=app(ProductionService::class)->batchIndex(true);
        foreach(ProductionItem::whereIn('line_item_id',array_keys($processed))->get() as $item) foreach($item->matches??[] as $file) if(!empty($file['id']))$ids[$file['id']]=true;
        return $ids;
    }
    private function stem(string $name): string { return trim(preg_replace('/[^A-Z0-9]+/','-',strtoupper(pathinfo($name,PATHINFO_FILENAME))),'-'); }
    private function fileDigest(array $file): string { $data=[];foreach(['id','name','parent_id','modifiedTime','md5Checksum','version','path'] as $key)$data[$key]=$file[$key]??null;return hash('sha256',json_encode($data)); }
    private function catalogDigest(array $catalog): string { usort($catalog,fn($a,$b)=>strcmp($a['catalog_key'],$b['catalog_key']));return hash('sha256',json_encode($catalog)); }
}
