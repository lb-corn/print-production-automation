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
        abort_if(!$allowExpired && ($plan['expires_at']??0)<time(),422,'This preview expired. Scan again.');
        return $plan;
    }
    public function save(array $plan): void
    {
        if(!is_dir($this->directory) && !mkdir($this->directory,0700,true) && !is_dir($this->directory))throw new RuntimeException('Private naming audit storage unavailable.');
        $path=$this->path($plan['token']);$temp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if(file_put_contents($temp,json_encode($plan,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX)===false)throw new RuntimeException('Cannot save naming audit.');
        if(!rename($temp,$path))throw new RuntimeException('Cannot finalize naming audit.');
    }
    private function path(string $token): string
    {
        abort_unless(preg_match('/^[a-f0-9]{48}$/D',$token),404);
        return $this->directory.DIRECTORY_SEPARATOR.$token.'.json';
    }
}
