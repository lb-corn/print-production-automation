<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** Lucky Bags metadata only. Never downloads or hosts artwork. */
class MaterialCatalog
{
    public const DEFAULT_SHEET_ID='1NyRZkS7ZFvsr2PmBLuj7eFABRkYlmBOSOdI442DooXM';
    private ?array $snapshot=null;
    private ?string $failure=null;
    public function rows(bool $fresh=false):array {
        if(!$fresh && $this->snapshot!==null)return $this->snapshot;
        try {
            $id=(string)config('services.materials.sheet_id',self::DEFAULT_SHEET_ID);
            if(!preg_match('/^[A-Za-z0-9_-]{20,100}$/',$id))throw new \RuntimeException('Sheet configuration missing');
            $response=Http::timeout(12)->connectTimeout(5)->withOptions(['allow_redirects'=>false])->get('https://docs.google.com/spreadsheets/d/'.$id.'/gviz/tq',['tqx'=>'out:csv','sheet'=>'Lucky Bags']);
            if(!$response->successful())throw new \RuntimeException('Google Sheets returned HTTP '.$response->status());
            $rows=$this->parse($response->body());
            $this->failure=null;
            return $this->snapshot=$rows;
        } catch(\Throwable $e) {
            $this->snapshot=[];
            $reason=$e instanceof \Illuminate\Http\Client\ConnectionException?'The server could not connect to Google Sheets.':(in_array($e->getMessage(),['Sheet configuration missing','Missing headings','Invalid headings','Invalid bag code or type','Invalid cell','Invalid row','Empty material sheet','Invalid sheet response'],true)?$e->getMessage().'.':(preg_match('/^Google Sheets returned HTTP [0-9]{3}$/',$e->getMessage())?$e->getMessage().'.':'The sheet could not be validated.'));
            $this->failure=$reason.' Check the Lucky Bags tab and sharing access, then refresh. New preparation is paused; existing batches are preserved.';
            if($fresh)throw ValidationException::withMessages(['materials'=>$this->failure]);
            return [];
        }
    }
    public function error():?string {$this->rows();return $this->failure;}
    public function map():array {return array_map(fn($r)=>[$r['front'],$r['back']],$this->rows());}
    public function row(?string $code):?array {return $this->rows()[strtoupper((string)$code)]??null;}
    public function materials():array {
        $names=[];foreach($this->rows() as $row){$names[]=$row['front'];if($row['back']!=='')$names[]=$row['back'];}
        $names=array_values(array_unique($names));natcasesort($names);return array_values($names);
    }
    public function fingerprint():string {return hash('sha256',json_encode($this->rows()));}
    public function parse(string $csv):array {
        if(strlen($csv)>524288 || str_contains($csv,'<html'))throw new \RuntimeException('Invalid sheet response');
        $stream=fopen('php://temp','r+');fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$csv));rewind($stream);
        try {
            $header=fgetcsv($stream,0,',','"','');
            if(!$header)throw new \RuntimeException('Missing headings');
            $header=array_map(fn($v)=>strtolower(trim($v)),$header);
            $required=['bag name','bag code','front material','back material','type','template size'];
            if(array_diff($required,$header) || count(array_unique($header))!==count($header))throw new \RuntimeException('Invalid headings');
            $result=[];$count=0;
            while(($values=fgetcsv($stream,0,',','"',''))!==false){
                if(++$count>1000)throw new \RuntimeException('Too many rows');
                if(!array_filter($values,fn($v)=>trim((string)$v)!==''))continue;
                if(count($values)!==count($header))throw new \RuntimeException('Invalid row');
                $row=array_combine($header,array_map(fn($v)=>trim((string)$v),$values));$code=strtoupper($row['bag code']);
                if(!preg_match('/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/',$code) || isset($result[$code]) || !in_array(strtolower($row['type']),['single','double'],true))throw new \RuntimeException('Invalid bag code or type');
                foreach($required as $key)if(($row[$key]==='' && !($key==='back material' && strtolower($row['type'])==='single')) || strlen($row[$key])>100 || preg_match('/[\x00-\x1F\x7F]/',$row[$key]))throw new \RuntimeException('Invalid cell');
                $result[$code]=['name'=>$row['bag name'],'front'=>$row['front material'],'back'=>$row['back material'],'type'=>strtolower($row['type']),'template_size'=>$row['template size']];
            }
            if(!$result)throw new \RuntimeException('Empty material sheet');
            ksort($result);return $result;
        } finally {fclose($stream);}
    }
}
