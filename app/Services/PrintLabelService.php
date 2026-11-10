<?php
namespace App\Services;
use App\Models\ProductionItem;
class PrintLabelService {
    public const POLICY='BOTTOM_WHITE_V3';
    public static function token(?string $value,int $limit=80):string {
        $text=\Illuminate\Support\Str::ascii(trim((string)$value));
        return substr(trim(preg_replace('/[^A-Z0-9]+/','-',strtoupper($text)),'-'),0,$limit)?:'NA';
    }
    public static function label(ProductionItem $item,string $material):string {
        $parts=[self::token(str_replace(' ','',$material),24)];
        $order=trim((string)$item->order_number);
        if($order!=='')$parts[]='LB'.self::token(ltrim($order,'#'),24);
        // Only single-sided bags have an unprinted backing to identify.
        $back=app(MaterialCatalog::class)->row($item->series_code)['back']??'';
        if($item->design_type==='single' && $back!==''){
            $parts[]=self::token($back,40);
            if(trim((string)$item->color)!=='')$parts[]=self::token($item->color,24);
        }
        return implode(' ',$parts);
    }
    public static function materials(ProductionItem $item):array {
        $map=app(MaterialCatalog::class)->map()[$item->series_code]??[];
        return $item->design_type==='double'?array_values(array_unique($map)):array_slice($map,0,1);
    }
    public static function group(ProductionItem $item):string {return implode(' + ',self::materials($item));}
}
