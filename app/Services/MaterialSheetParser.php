<?php
namespace App\Services;

use RuntimeException;

/** Reads material tables by headings; titles and backing colors are separate sections. */
class MaterialSheetParser
{
    public function parse(array $values): array
    {
        if (count($values)>1000) throw new RuntimeException('Too many rows');
        $required=['bag name','bag code','front material','back material','type','template size'];
        $header=null;$start=null;
        foreach ($values as $index=>$row) {
            $normalized=array_map(fn($v)=>strtolower(trim((string)$v)),$row);
            if (!array_diff($required,$normalized)) {
                if (count(array_filter($normalized,fn($v)=>$v!==''))!==count(array_unique(array_filter($normalized,fn($v)=>$v!=='')))) throw new RuntimeException('Invalid headings');
                $header=array_flip($normalized);$start=$index+1;break;
            }
        }
        if ($header===null) throw new RuntimeException('Invalid headings');
        $bags=[];$end=$start;
        for ($i=$start;$i<count($values);$i++) {
            $row=$values[$i];
            $nonempty=array_filter($row,fn($v)=>trim((string)$v)!=='');
            if (!$nonempty) continue;
            if (count($nonempty)===1 && preg_match('/single\s+side\s+back\s+material\s+configuration/i',(string)reset($nonempty))) {$end=$i;break;}
            $record=[];foreach ($required as $key) $record[$key]=trim((string)($row[$header[$key]]??''));
            $code=strtoupper($record['bag code']);$type=strtolower($record['type']);
            if (!preg_match('/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/',$code) || isset($bags[$code]) || !in_array($type,['single','double'],true)) throw new RuntimeException('Invalid bag code or type');
            foreach ($required as $key) if (($record[$key]==='' && !($key==='back material' && $type==='single')) || strlen($record[$key])>100 || preg_match('/[\x00-\x1F\x7F]/',$record[$key])) throw new RuntimeException('Invalid cell');
            $bags[$code]=['name'=>$record['bag name'],'front'=>$record['front material'],'back'=>$record['back material'],'type'=>$type,'template_size'=>$record['template size']];
            $end=$i+1;
        }
        if (!$bags) throw new RuntimeException('Empty material sheet');
        $colors=[];$colorHeader=null;$section=false;
        for ($i=$end;$i<count($values);$i++) {
            $row=$values[$i];$nonempty=array_filter($row,fn($v)=>trim((string)$v)!=='');
            if (!$nonempty) continue;
            if (!$section) {
                if (count($nonempty)===1 && preg_match('/single\s+side\s+back\s+material\s+configuration/i',(string)reset($nonempty))) $section=true;
                continue;
            }
            if ($colorHeader===null) {
                $colorHeader=[];
                foreach ($row as $column=>$value) {
                    $material=trim((string)$value);if ($material==='')continue;
                    if (isset($colors[strtolower($material)]))throw new RuntimeException('Duplicate backing material');
                    $colorHeader[$column]=$material;$colors[strtolower($material)]=['material'=>$material,'colors'=>[]];
                }
                continue;
            }
            foreach ($colorHeader as $column=>$material) {
                $color=trim((string)($row[$column]??''));if ($color==='')continue;
                if (strlen($color)>100 || preg_match('/[\x00-\x1F\x7F]/',$color))throw new RuntimeException('Invalid backing color');
                if(in_array(strtolower($color),array_map('strtolower',$colors[strtolower($material)]['colors']),true))throw new RuntimeException('Duplicate backing color');
                $colors[strtolower($material)]['colors'][]=$color;
            }
        }
        ksort($bags);
        return ['bags'=>$bags,'backing_materials'=>array_values($colors)];
    }
}
