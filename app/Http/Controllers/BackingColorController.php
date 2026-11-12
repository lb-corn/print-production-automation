<?php
namespace App\Http\Controllers;

use App\Models\ProductionItem;
use App\Services\{BackingColorService,BackingSwatchService,ProductionService};
use Illuminate\Http\Request;

class BackingColorController extends Controller
{
    public static function swatchUrl(string $material,string $color):string {
        return route('backing.reference',['material'=>$material,'color'=>$color,'partner'=>\App\Services\PartnerContext::current()]);
    }
    public function reference(Request $request) {
        abort_unless(config('services.backing_colors.enabled') && \App\Services\PartnerContext::current()!=='all',404);
        $material=$request->query('material');$color=$request->query('color');
        abort_unless(is_string($material) && is_string($color),404);
        $colors=app(\App\Services\MaterialCatalog::class)->backingColors($material);
        abort_unless(in_array($color,$colors,true),404);
        $files=app(BackingSwatchService::class)->files($material,$colors);
        abort_unless(isset($files[$color]),404);
        return $this->imageResponse(app(\App\Services\BackingReferenceLoader::class)->swatch($files[$color]));
    }
    private function imageResponse(string $bytes) {
        $size=@getimagesizefromstring($bytes);
        abort_unless($size && in_array($size['mime']??'', ['image/jpeg','image/png','image/webp'],true),404);
        return response($bytes)->header('Content-Type',$size['mime'])->header('X-Content-Type-Options','nosniff')->header('Cache-Control','private, max-age=300')->header('Vary','Cookie');
    }
    public function swatch(Request $request,ProductionItem $item) {
        abort_unless(BackingColorService::required($item),404);
        $definition=BackingColorService::definition($item);$color=$request->query('color');
        abort_unless(is_string($color) && in_array($color,$definition['colors'],true),404);
        $files=app(BackingSwatchService::class)->files($definition['material'],$definition['colors']);
        abort_unless(isset($files[$color]),404);
        $bytes=app(\App\Services\BackingReferenceLoader::class)->swatch($files[$color]);
        return $this->imageResponse($bytes);
    }
    public function suggest(ProductionItem $item) {
        abort_unless(BackingColorService::required($item),422);
        abort_if(isset(app(ProductionService::class)->batchIndex(true)[$item->line_item_id]),409,'This order is already in a locked batch.');
        $source=request()->query('fpd_backside');
        abort_if($source!==null && (!$item->is_custom || !is_string($source) || !\App\Services\BackingReferenceLoader::validFpdBackside($source)),422,'Use the original FPD backside image link for a custom single-sided bag.');
        $definition=BackingColorService::definition($item);$result=['suggested'=>null,'candidates'=>[],'message'=>$item->is_custom?'Open view 2 in FPD and paste its backside image link to compare, or choose an allowed color manually.':'Needs confirmation. Compare the website backside with the sheet colors and select one.'];$files=[];
        try {
            $service=app(BackingSwatchService::class);$files=$service->files($definition['material'],$definition['colors']);
            if($item->is_custom){if($source!==null)$result=$service->suggestFpd($item,$source);}
            else $result=$service->suggest($item);
        } catch(\Throwable $e){report($e);}
        return response()->json($result+['context'=>BackingColorService::context($item),
            'swatches'=>array_map(fn($color)=>['color'=>$color,'image'=>isset($files[$color])?self::swatchUrl($definition['material'],$color):null],$definition['colors'])])->header('Cache-Control','no-store');
    }
}
