<?php
namespace App\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

class BackingSwatchService
{
    public function files(string $material,array $colors):array {
        $root=(string)config('services.google_drive.artwork_root_id');$map=config('services.backing_colors.folder_map',[]);
        $key='backing.swatches.'.hash('sha256',json_encode([$root,$material,$colors,$map,config('services.backing_colors.folder_name')]));
        return Cache::remember($key,300,function()use($root,$material,$colors,$map){
            $drive=app(GoogleDriveService::class);
            $roots=array_values(array_filter($drive->listChildren($root),fn($f)=>$f['mimeType']==='application/vnd.google-apps.folder' && $f['name']===config('services.backing_colors.folder_name')));
            if(count($roots)!==1)throw new RuntimeException('Backing swatch folder not found.');
            $folders=array_values(array_filter($drive->listChildren($roots[0]['id']),fn($f)=>$f['mimeType']==='application/vnd.google-apps.folder' && (isset($map[$material])?$f['id']===$map[$material]:strcasecmp($f['name'],$material)===0)));
            if(count($folders)!==1)throw new RuntimeException('Backing material folder is missing or ambiguous.');
            $found=[];
            foreach($drive->listChildren($folders[0]['id']) as $file){
                if(!in_array($file['mimeType'],['image/jpeg','image/png','image/webp'],true))continue;
                $stem=pathinfo($file['name'],PATHINFO_FILENAME);
                foreach($colors as $color)if(strcasecmp($stem,$color)===0){if(isset($found[$color]))throw new RuntimeException('Duplicate backing color swatches.');$found[$color]=$file;}
            }
            return $found;
        });
    }
    public function suggest($item):array {
        $definition=BackingColorService::definition($item);
        if(!$definition['colors'])throw new RuntimeException('No backing colors are configured in the sheet.');
        $files=$this->files($definition['material'],$definition['colors']);
        if(count($files)!==count($definition['colors']))return ['suggested'=>null,'candidates'=>[],'message'=>'Some allowed colors have no swatch image. Select the backing color manually; automatic comparison is unavailable.'];
        $key='backing.suggestion.'.hash('sha256',json_encode([BackingColorService::context($item),array_map(fn($f)=>[$f['id'],$f['version']??null,$f['md5Checksum']??null],$files)]));
        return Cache::remember($key,600,function()use($item,$files){
            $loader=app(BackingReferenceLoader::class);$matcher=app(BackingImageMatcher::class);
            $product=$loader->product((string)$item->shopify_image);$profiles=[];
            foreach($files as $color=>$file){
                $profileKey='backing.profile.'.hash('sha256',json_encode([$file['id'],$file['version']??null,$file['md5Checksum']??null]));
                $profiles[$color]=Cache::remember($profileKey,86400,fn()=>$matcher->profile($loader->swatch($file)));
            }
            return $matcher->compare($product,$profiles);
        });
    }
}
