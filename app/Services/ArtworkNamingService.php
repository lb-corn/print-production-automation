<?php
namespace App\Services;

use App\Models\{PrintBatch,ProductionItem,VerificationDecision};
use Illuminate\Support\Facades\DB;

class ArtworkNamingService
{
    public const POLICY='all-bags-active-v2';
    public function __construct(private GoogleDriveService $drive,private ShopifyService $shopify,
        private ArtworkMatcherService $matcher,private ArtworkRenameStore $store) {}

    public function scan(int $actor): array
    {
        $index=$this->drive->artworkIndex();$catalog=$this->shopify->getArtworkCatalog();$rules=$this->store->rules();
        return $this->store->create($actor,['naming_policy'=>self::POLICY,'rules'=>$rules,'rules_digest'=>$this->rulesDigest($rules),'index'=>$index,'catalog'=>$catalog,'catalog_digest'=>$this->catalogDigest($catalog),
            'entries'=>$this->build($index,$catalog,[], $this->lockedFiles(),$rules),'mappings'=>[]]);
    }
    public function revise(string $token,int $actor,array $mappings,bool $reuse=false): array
    {
        $plan=$this->store->load($token,$actor);$this->requirePolicy($plan);abort_unless($plan['status']==='preview',422,'This plan is already submitted. Scan again.');
        $entries=array_column($plan['entries'],null,'id');
        foreach($mappings as $id=>$mapping) {
            abort_unless(isset($entries[$id]) && $entries[$id]['status']!=='locked',422,'Choose an unbatched file from this preview.');
            $folderName=trim(substr($entries[$id]['folder'],strrpos($entries[$id]['folder'],'/')+1));
            $attributes=$entries[$id]['source_attributes']??$this->matcher->sourceAttributes($entries[$id]['source'],$folderName);
            $row=collect($plan['catalog'])->firstWhere('catalog_key',$mapping['catalog_key']??'');
            abort_unless($row,422,'Choose a Shopify variant from this preview.');
            $expectedColor=$this->matcher->catalogColor($row);
            $colorMismatch=$expectedColor && !empty($attributes['color']) && $attributes['color']!==$expectedColor;
            abort_if($colorMismatch && ($mapping['color_correction_confirmed']??false)!==true,422,'The filename color differs from the Shopify variant. Explicitly confirm its incorrect color label first.');
            if($colorMismatch) {
                abort_unless(($mapping['color']??null)===$expectedColor,422,'The corrected color must equal the selected Shopify variant.');
                $plan['color_correction_reviews'][]=['file_id'=>$id,'original_name'=>$entries[$id]['source']['name'],'from'=>$attributes['color'],'to'=>$expectedColor,'actor'=>$actor,'at'=>gmdate('c')];
            }
            abort_if(in_array($attributes['side']??null,['front','back'],true) && ($mapping['side']??null)!==$attributes['side'],422,'The selected side differs from FRONT/BACK in the filename.');
            if(!empty($mapping['catalog_key']))$plan['mappings'][$id]=$mapping;
            if($reuse && !$colorMismatch) {
                $folderName=trim(substr($entries[$id]['folder'],strrpos($entries[$id]['folder'],'/')+1));
                $attributes=$entries[$id]['source_attributes']??$this->matcher->sourceAttributes($entries[$id]['source'],$folderName);
                $row=collect($plan['catalog'])->firstWhere('catalog_key',$mapping['catalog_key']??'');
                $verified=$row?$this->matcher->namingCandidate($row,$entries[$id]['source'],$folderName,$mapping):null;
                if($attributes && $verified && ($entries[$id]['candidate_count']??0)<=1)$this->store->remember($attributes['design'],$this->matcher->designIdentity($row['product']),$actor);
            }
        }
        $plan['rules']=$this->store->rules();$plan['rules_digest']=$this->rulesDigest($plan['rules']);
        $plan['entries']=$this->build($plan['index'],$plan['catalog'],$plan['mappings'],$this->lockedFiles(),$plan['rules']);
        $this->store->save($plan);return $plan;
    }
    public function revisePair(string $token,int $actor,string $catalogKey,string $frontId,string $backId,bool $reuse=false,bool $confirmColorCorrection=false): array
    {
        $plan=$this->store->load($token,$actor);$this->requirePolicy($plan);
        abort_unless($frontId!==$backId,422,'Front and back must be different files.');
        $row=collect($plan['catalog'])->firstWhere('catalog_key',$catalogKey);
        abort_unless($row && $this->matcher->match($row,[])['design_type']==='double',422,'Choose a double-sided Shopify variant.');
        $folders=array_values(array_filter($plan['index'],fn($entry)=>($entry['kind']??'')==='series_folder'));
        abort_if($this->matcher->match($row,$folders)['match_status']==='AMBIGUOUS',422,'The series folder is ambiguous. Correct its location before choosing artwork.');
        $entries=array_column($plan['entries'],null,'id');$mappings=[];$colors=[];
        foreach(['front'=>$frontId,'back'=>$backId] as $side=>$id) {
            abort_unless(isset($entries[$id]) && $entries[$id]['status']!=='locked',422,'Choose two unbatched files from this preview.');
            $entry=$entries[$id];$folder=trim(substr($entry['folder'],strrpos($entry['folder'],'/')+1));
            $attributes=$entry['source_attributes']??$this->matcher->sourceAttributes($entry['source'],$folder);
            abort_if(in_array($attributes['side']??null,['front','back'],true) && $attributes['side']!==$side,422,'FRONT/BACK in the filename must agree with the selected slot.');
            abort_if(($attributes['side']??null)==='single',422,'A SINGLE artwork file cannot be used for a double-sided pair.');
            $expected=$this->matcher->catalogColor($row);$named=$attributes['color']??null;
            abort_if($expected && $named && $expected!==$named && !$confirmColorCorrection,422,'The artwork color differs from the Shopify variant.');
            if($named)$colors[]=$named;
            $mapping=['catalog_key'=>$catalogKey,'side'=>$side,'color'=>$expected??$named??'','color_correction_confirmed'=>$confirmColorCorrection];
            abort_unless($this->matcher->namingCandidate($row,$entry['source'],$folder,$mapping),422,'The pair must use eligible production artwork from this series folder.');
            $mappings[$id]=$mapping;
        }
        abort_if(count(array_unique($colors))>1 && !($confirmColorCorrection && $this->matcher->catalogColor($row)),422,'Front and back have conflicting filename colors.');
        return $this->revise($token,$actor,$mappings,$reuse);
    }
    public function begin(string $token,int $actor,array $ids,bool $refresh): array
    {
        abort_unless(config('services.google_drive.rename_enabled',false),403,'Enable Drive renaming on the server before applying a plan.');
        ArtworkRematchService::ensureSchemaReady();
        $plan=$this->store->load($token,$actor);$this->requirePolicy($plan);abort_unless($plan['status']==='preview',422,'This plan was already submitted. Scan again.');
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
        $plan=$this->store->load($token,$actor);$this->requirePolicy($plan);abort_unless($plan['status']==='applying',422,'No active rename operation.');
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
        if($checkCatalog)abort_unless(($plan['rules_digest']??'')===$this->rulesDigest($this->store->rules()),422,'Design mappings changed. Check files again.');
        if($checkCatalog)abort_unless($this->catalogDigest($this->shopify->getArtworkCatalog())===$plan['catalog_digest'],422,'Shopify catalog changed. Scan again.');
        $index=$this->drive->artworkIndex();$locked=$this->lockedFiles();
        $current=array_column(array_filter($index,fn($f)=>($f['kind']??'')==='artwork'),null,'id');
        $entries=$this->build($index,$plan['catalog'],$plan['mappings'],$locked,$plan['rules']??[]);$entries=array_column($entries,null,'id');
        $original=array_column($plan['entries'],null,'id');
        foreach($ids as $id) {
            abort_unless(isset($current[$id],$original[$id],$entries[$id]) && $entries[$id]['status']==='ready'
                && $entries[$id]['proposal']===$original[$id]['proposal'] && $this->fileDigest($current[$id])===$this->fileDigest($original[$id]['source']),422,'File, folder, mapping or destination changed. Scan again.');
        }
        return $current;
    }
    public function build(array $index,array $catalog,array $mappings,array $locked,array $rules=[]): array
    {
        $folders=array_column(array_filter($index,function($folder){
            if(($folder['kind']??'')!=='series_folder')return false;
            $parts=array_map('trim',explode('/',$folder['path']??''));
            return count($parts)===2 && in_array($parts[0],config('services.google_drive.allowed_root_folders',[]),true)
                && $parts[1]===($folder['name']??null) && ArtworkMatcherService::seriesForFolder($folder['name'])!==null;
        }),null,'id');
        $catalogColors=array_values(array_filter(array_map(fn($row)=>$this->matcher->catalogColor($row),$catalog)));
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
            $attributes=$this->matcher->sourceAttributes($file,$folder['name'],$catalogColors);
            $basis=$mapping?'Confirmed file mapping':'Exact filename';
            $rule=$attributes?($rules[$attributes['design']]??null):null;
            if(!$mapping && $rule) {
                $conflicting=false;foreach($choices as $choice)if($choice['design_identity']!==$rule)$conflicting=true;
                if($conflicting)$choices=[];
                elseif(!$choices && $attributes['side']) {
                    foreach($catalog as $row) {
                        if($this->matcher->designIdentity($row['product'])!==$rule)continue;
                        $candidate=$this->matcher->namingCandidate($row,$file,$folder['name'],['side'=>$attributes['side'],'color'=>$attributes['color']??'']);
                        if(!$candidate || $this->matcher->match($row,array_values($folders))['match_status']==='AMBIGUOUS')continue;
                        $choices[]=$candidate;
                    }
                    $basis='Remembered design mapping';
                }
            }
            // Equivalent catalog rows are evidence for one destination, not different artwork choices.
            $groups=[];
            usort($choices,fn($a,$b)=>strcmp($a['catalog_key'],$b['catalog_key']));
            foreach($choices as $choice)$groups[$choice['target'].'/'.$choice['design_identity']][]=$choice;
            $proposal=count($groups)===1?array_values($groups)[0][0]:null;
            if($proposal)$proposal['catalog_keys']=array_column(array_values($groups)[0],'catalog_key');
            $reason=$proposal?null:(count($groups)>1?'More than one catalog design fits this file.':'Design label, catalog variant or print side needs confirmation.');
            if($rule && !$proposal)$reason='Saved design mapping cannot resolve this color, side or catalog conflict.';
            $status=isset($locked[$file['id']])?'locked':(!$proposal?'review':($file['name']===$proposal['target']?'standard':'ready'));
            $entry=['id'=>$file['id'],'source'=>$file,'folder'=>$folder['path'],'status'=>$status,'proposal'=>$proposal,'candidate_count'=>count($groups),'source_attributes'=>$attributes,'source_design'=>$attributes['design']??null,'reason'=>$reason,'basis'=>$basis];
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
        foreach(PrintBatch::where('status','!=','CANCELLED')->get() as $batch)$walk($batch->payload);
        $processed=app(ProductionService::class)->batchIndex(true);
        foreach(ProductionItem::whereIn('line_item_id',array_keys($processed))->get() as $item) foreach($item->matches??[] as $file) if(!empty($file['id']))$ids[$file['id']]=true;
        return $ids;
    }
    private function requirePolicy(array $plan): void { abort_unless(($plan['naming_policy']??null)===self::POLICY,422,'Naming rules changed. Click Check files to prepare a new preview.'); }
    private function rulesDigest(array $rules): string { ksort($rules);return hash('sha256',json_encode($rules)); }
    private function stem(string $name): string { return trim(preg_replace('/[^A-Z0-9]+/','-',strtoupper(pathinfo($name,PATHINFO_FILENAME))),'-'); }
    private function fileDigest(array $file): string { $data=[];foreach(['id','name','parent_id','modifiedTime','md5Checksum','version','path'] as $key)$data[$key]=$file[$key]??null;return hash('sha256',json_encode($data)); }
    private function catalogDigest(array $catalog): string { usort($catalog,fn($a,$b)=>strcmp($a['catalog_key'],$b['catalog_key']));return hash('sha256',json_encode($catalog)); }
}
