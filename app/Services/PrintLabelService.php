<?php
namespace App\Services;
use App\Models\ProductionItem;
class PrintLabelService {
    public const POLICY='BOTTOM_WHITE_V2';
    public static function token(?string $value,int $limit=80):string {
        $text=\Illuminate\Support\Str::ascii(trim((string)$value));
        return substr(trim(preg_replace('/[^A-Z0-9]+/','-',strtoupper($text)),'-'),0,$limit)?:'NA';
    }
    public static function label(ProductionItem $item,string $material):string {
        $order=preg_replace('/[^0-9]/','',(string)$item->order_number);
        $name=preg_replace('/^Lucky\s+Bags\s+(?:20\d{2}\s+)?/i','',(string)$item->product);
        return substr('LBSH'.($order?:self::token($item->line_item_id,16)).'-'.self::token(str_replace(' ','',$material),16).'-'.self::token($item->color,24).'-'.self::token($name,60),0,120);
    }
    public static function materials(ProductionItem $item):array {
        $map=app(MaterialCatalog::class)->map()[$item->series_code]??[];
        return $item->design_type==='double'?array_values(array_unique($map)):array_slice($map,0,1);
    }
    public static function group(ProductionItem $item):string {return implode(' + ',self::materials($item));}
}
