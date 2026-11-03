<?php
namespace App\Services;

use RuntimeException;

/** Private, durable plans double as before/after audit ledgers. No web-accessible files. */
class ArtworkRenameStore
{
    private string $directory;
    public function __construct(?string $directory=null) { $this->directory=$directory ?? storage_path('app/private/artwork-names'); }
    public function create(int $actor, array $data): array
    {
        $plan=['token'=>bin2hex(random_bytes(24)),'actor'=>$actor,'created_at'=>time(),'expires_at'=>time()+1800,'status'=>'preview','events'=>[],...$data];
        $this->save($plan); return $plan;
    }
    public function load(string $token,int $actor,bool $allowExpired=false): array
    {
        $path=$this->path($token);
        abort_unless(is_file($path),404,'Artwork naming plan not found. Scan again.');
        $plan=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        abort_unless(($plan['actor']??null)===$actor,403);
        abort_if(!$allowExpired && !empty($plan['catalog_stale']),422,'A Drive filename changed. Refresh the artwork page before continuing.');
        abort_if(!$allowExpired && ($plan['expires_at']??0)<time(),422,'This preview expired. Scan again.');
        return $plan;
    }
    /** Read-only dashboard counts; never restores authorization from an old preview. */
    public function latestPreview(int $actor): ?array
    {
        $latest=null;
        foreach(glob($this->directory.DIRECTORY_SEPARATOR.'*.json')?:[] as $path) {
            if(!preg_match('/^[a-f0-9]{48}\.json$/D',basename($path)))continue;
            try{$plan=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}catch(\JsonException $e){continue;}
            if(!empty($plan['standardize']) || !empty($plan['catalog_stale']) || ($plan['actor']??null)!==$actor || ($plan['naming_policy']??null)!==ArtworkNamingService::POLICY || ($plan['status']??null)!=='preview' || ($plan['expires_at']??0)<time() || ($plan['created_at']??0)<time()-120)continue;
            if(!$latest || $plan['created_at']>$latest['created_at'])$latest=$plan;
        }
        return $latest;
    }
    public function latestSummary(int $actor): ?array
    {
        $latest=null;
        foreach(glob($this->directory.DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
            if(!preg_match('/^[a-f0-9]{48}\.json$/D',basename($path)))continue;
            try{$plan=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}catch(\JsonException $e){continue;}
            if(!empty($plan['catalog_stale']) || ($plan['actor']??null)!==$actor || ($plan['naming_policy']??null)!==ArtworkNamingService::POLICY)continue;
            if($latest===null || ($plan['created_at']??0)>($latest['created_at']??0))$latest=$plan;
        }
        return $latest ? self::summary($latest) : null;
    }
    /** Retire cached previews, including bookmarked tokens, without deleting audit history. */
    public function invalidateCatalogPreviews(string $fileId): void
    {
        foreach(glob($this->directory.DIRECTORY_SEPARATOR.'*.json')?:[] as $path) {
            if(!preg_match('/^[a-f0-9]{48}\.json$/D',basename($path)))continue;
            try{$preview=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}catch(\JsonException $e){continue;}
            if(($preview['status']??null)!=='preview' || !empty($preview['catalog_stale']))continue;
            if(!in_array($fileId,array_column($preview['index']??[],'id'),true))continue;
            $preview['catalog_stale']=true;$this->save($preview);
        }
    }
    public static function summary(array $plan): array
    {
        $linked=array_values(array_filter($plan['entries'],fn($entry)=>!empty($entry['proposal'])));
        $counts=array_count_values(array_column($linked,'status'));
        $coverage=['matched'=>0,'missing'=>0,'ambiguous'=>0];
        $matcher=new ArtworkMatcherService();
        foreach($plan['catalog'] as $row) {
            $profile=$matcher->match($row,$plan['index']??[]);
            if(($profile['artwork_review']['selection_source']??'')==='admin'){$coverage['matched']++;continue;}
            if(($profile['artwork_review']['selection_source']??'')==='invalidated_admin'){$coverage['missing']++;continue;}
            $required=$profile['design_type']==='double'?['front','back']:['single'];
            $sides=[];foreach($linked as $entry)if(in_array($row['catalog_key'],$entry['proposal']['catalog_keys']??[$entry['proposal']['catalog_key']??null],true))$sides[]=$entry['proposal']['side'];
            $sideCounts=array_count_values($sides);$complete=true;$ambiguous=false;
            foreach($required as $side){if(!($sideCounts[$side]??0))$complete=false;if(($sideCounts[$side]??0)>1)$ambiguous=true;}
            $coverage[$ambiguous?'ambiguous':($complete?'matched':'missing')]++;
        }
        $matcher=new ArtworkMatcherService();
        $products=array_unique(array_map(fn($row)=>$row['product_id']??$matcher->designIdentity($row['product']),$plan['catalog']));
        return ['images'=>count($linked),'products'=>count($products),'variants'=>count($plan['catalog']),
            'good'=>($counts['standard']??0)+($counts['renamed']??0),'ready'=>$counts['ready']??0,
            'review'=>($counts['review']??0)+($counts['conflict']??0),'protected'=>$counts['locked']??0,'catalog_matched'=>$coverage['matched'],'catalog_missing'=>$coverage['missing'],'catalog_ambiguous'=>$coverage['ambiguous'],'checked_at'=>$plan['created_at']];
    }
    public function preferredFilenames(): array
    {
        $path=$this->directory.DIRECTORY_SEPARATOR.'filename-preferences.json';
        return is_file($path)?json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR):[];
    }
    public function rememberFilename(array $file,int $actor): void
    {
        $names=$this->preferredFilenames();$names[$file['id']]=['name'=>$file['name'],'fingerprint'=>ArtworkMatcherService::artworkFingerprint($file),'policy'=>ArtworkNamingService::POLICY,'actor'=>$actor,'at'=>gmdate('c')];
        $this->write($this->directory.DIRECTORY_SEPARATOR.'filename-preferences.json',$names);
    }
    public function selections(): array
    {
        $path=$this->directory.DIRECTORY_SEPARATOR.'artwork-selections.json';
        return is_file($path)?json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR):[];
    }
    public function saveSelection(string $key,array $selection): void
    {
        $data=$this->selections();$data[$key]=$selection;
        $this->write($this->directory.DIRECTORY_SEPARATOR.'artwork-selections.json',$data);
    }
    public function refreshRenamedSelection(array $before,array $after): void
    {
        $data=$this->selections();$changed=false;
        foreach($data as &$selection)foreach($selection['files'] as &$file)if(($file['source']['id']??null)===$before['id'] && ArtworkMatcherService::artworkFingerprint($file['source'])===ArtworkMatcherService::artworkFingerprint($before)) {
            $file['source']=$after;$changed=true;
        }
        unset($selection,$file);
        if($changed)$this->write($this->directory.DIRECTORY_SEPARATOR.'artwork-selections.json',$data);
    }
    public function rules(): array
    {
        $path=$this->directory.DIRECTORY_SEPARATOR.'design-mappings.json';
        if(!is_file($path))return [];
        $data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        return $data['rules'] ?? [];
    }
    public function remember(string $source,string $product,int $actor): void
    {
        $path=$this->directory.DIRECTORY_SEPARATOR.'design-mappings.json';
        $data=is_file($path)?json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR):['rules'=>[],'events'=>[]];
        $data['events'][]=['source'=>$source,'product'=>$product,'actor'=>$actor,'at'=>gmdate('c'),'previous'=>$data['rules'][$source]??null];
        $data['rules'][$source]=$product;
        $this->write($path,$data);
    }
    public function save(array $plan): void { $this->write($this->path($plan['token']),$plan); }
    private function write(string $path,array $data): void
    {
        if(!is_dir($this->directory) && !mkdir($this->directory,0700,true) && !is_dir($this->directory))throw new RuntimeException('Private naming audit storage unavailable.');
        $temp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if(file_put_contents($temp,json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX)===false)throw new RuntimeException('Cannot save naming audit.');
        if(!rename($temp,$path))throw new RuntimeException('Cannot finalize naming audit.');
    }
    private function path(string $token): string
    {
        abort_unless(preg_match('/^[a-f0-9]{48}$/D',$token),404);
        return $this->directory.DIRECTORY_SEPARATOR.$token.'.json';
    }
}
