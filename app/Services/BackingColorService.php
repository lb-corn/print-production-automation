<?php
namespace App\Services;

use App\Models\{ProductionItem,VerificationDecision};
use Illuminate\Support\Facades\{DB,Schema};

class BackingColorService
{
    public static function required($item):bool {
        return config('services.backing_colors.enabled',false) && $item->design_type==='single'
            && (app(MaterialCatalog::class)->row($item->series_code)['back']??'')!=='';
    }
    public static function definition($item):array {
        $catalog=app(MaterialCatalog::class);$row=$catalog->row($item->series_code);
        $material=$row['back']??'';
        return ['material'=>$material,'colors'=>$catalog->backingColors($material)];
    }
    public static function context($item):string {
        return hash('sha256',json_encode([self::definition($item),$item->series_code,$item->design_type,$item->sku,$item->variant,$item->shopify_image,
            config('services.google_drive.artwork_root_id'),config('services.materials.sheet_id'),config('services.materials.sheet_tab')]));
    }
    public static function confirmed($item):bool {
        if(!self::required($item))return true;
        $selection=$item->backing_selection??[];$definition=self::definition($item);
        return isset($selection['color'],$selection['context'],$selection['confirmed_by'])
            && $selection['context']===self::context($item) && $selection['material']===$definition['material']
            && in_array($selection['color'],$definition['colors'],true);
    }
    public static function color($item):?string {
        return self::required($item) && self::confirmed($item)?$item->backing_selection['color']:null;
    }
    public static function approvalHash($item):?string {
        return self::required($item) && self::confirmed($item)?hash('sha256',json_encode($item->backing_selection)):null;
    }
    /** Caller holds the item lock; saved atomically with the artwork decision. */
    public function selectForApproval(ProductionItem $item,string $color,string $context,int $actor):void {
        abort_unless(Schema::hasColumn('production_items','backing_selection'),503,'Run the backing-color database migration first.');
        abort_unless(self::required($item),422,'This order does not use an unprinted backing.');
        abort_unless(hash_equals(self::context($item),$context),409,'The source image or material settings changed. Reload the backing colors.');
        $definition=self::definition($item);
        abort_unless(in_array($color,$definition['colors'],true),422,'Select a backing color from the material sheet before approval.');
        if(self::confirmed($item) && $item->backing_selection['color']===$color)return;
        $item->backing_selection=['material'=>$definition['material'],'color'=>$color,'context'=>$context,'source_image'=>$item->shopify_image,'sheet_id'=>config('services.materials.sheet_id'),'sheet_tab'=>config('services.materials.sheet_tab'),'confirmed_by'=>$actor,'confirmed_at'=>now()->toIso8601String()];
        $item->save();
    }
    public function confirm(ProductionItem $item,string $color,string $context,int $actor):void {
        app(MaterialCatalog::class)->rows(true);
        DB::transaction(function()use($item,$color,$context,$actor){
            $locked=ProductionItem::lockForUpdate()->findOrFail($item->id);
            abort_if(isset(app(ProductionService::class)->batchIndex(true)[$locked->line_item_id]) || $locked->decision?->decision==='CORRECT',409,'Return this approved item to Artwork Review before changing it.');
            $this->selectForApproval($locked,$color,$context,$actor);
        });
    }
}
