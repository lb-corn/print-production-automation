<?php
namespace App\Http\Controllers;

use App\Models\ProductionItem;
use App\Services\{BackingColorService,BackingSwatchService,ProductionService};
use Illuminate\Http\Request;

class BackingColorController extends Controller
{
    public function suggest(ProductionItem $item) {
        abort_unless(BackingColorService::required($item),422);
        abort_if(isset(app(ProductionService::class)->batchIndex(true)[$item->line_item_id]),409,'This order is already in a locked batch.');
        $definition=BackingColorService::definition($item);$result=['suggested'=>null,'candidates'=>[],'message'=>'Needs confirmation. Compare the website backside with the sheet colors and select one.'];$files=[];
        try {
            $service=app(BackingSwatchService::class);$files=$service->files($definition['material'],$definition['colors']);
            $result=$service->suggest($item);
        } catch(\Throwable $e){report($e);}
        return response()->json($result+['context'=>BackingColorService::context($item),
            'swatches'=>array_map(fn($color)=>['color'=>$color,'image'=>isset($files[$color])?'https://drive.google.com/thumbnail?id='.rawurlencode($files[$color]['id']).'&sz=w240':null],$definition['colors'])])->header('Cache-Control','no-store');
    }
}
