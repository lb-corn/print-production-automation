<?php
namespace App\Http\Controllers;

use App\Models\ProductionItem;
use App\Services\{BackingColorService,BackingSwatchService,ProductionService};
use Illuminate\Http\Request;

class BackingColorController extends Controller
{
    public function swatch(Request $request,ProductionItem $item) {
        abort_unless(BackingColorService::required($item),404);
        $definition=BackingColorService::definition($item);$color=$request->query('color');
        abort_unless(is_string($color) && in_array($color,$definition['colors'],true),404);
        $files=app(BackingSwatchService::class)->files($definition['material'],$definition['colors']);
        abort_unless(isset($files[$color]),404);
        $bytes=app(\App\Services\BackingReferenceLoader::class)->swatch($files[$color]);
        $size=@getimagesizefromstring($bytes);
        abort_unless($size && in_array($size['mime']??'', ['image/jpeg','image/png','image/webp'],true),404);
        return response($bytes)->header('Content-Type',$size['mime'])->header('X-Content-Type-Options','nosniff')->header('Cache-Control','private, max-age=300')->header('Vary','Cookie');
    }
    public function suggest(ProductionItem $item) {
        abort_unless(BackingColorService::required($item),422);
        abort_if(isset(app(ProductionService::class)->batchIndex(true)[$item->line_item_id]),409,'This order is already in a locked batch.');
        $definition=BackingColorService::definition($item);$result=['suggested'=>null,'candidates'=>[],'message'=>'Needs confirmation. Compare the website backside with the sheet colors and select one.'];$files=[];
        try {
            $service=app(BackingSwatchService::class);$files=$service->files($definition['material'],$definition['colors']);
            $result=$service->suggest($item);
        } catch(\Throwable $e){report($e);}
        return response()->json($result+['context'=>BackingColorService::context($item),
            'swatches'=>array_map(fn($color)=>['color'=>$color,'image'=>isset($files[$color])?route('backing.swatch',['item'=>$item->id,'color'=>$color,'partner'=>\App\Services\PartnerContext::current()]):null],$definition['colors'])])->header('Cache-Control','no-store');
    }
}
