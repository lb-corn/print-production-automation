<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** Lucky Bags metadata only. Never downloads or hosts artwork. */
class MaterialCatalog
{
    public const DEFAULT_SHEET_ID='1NyRZkS7ZFvsr2PmBLuj7eFABRkYlmBOSOdI442DooXM';
    private ?array $snapshot=null;
    private ?string $snapshotPartner=null;
    private array $backingMaterials=[];
    private ?string $failure=null;
    private array $partnerSnapshots=[];
    public function rows(bool $fresh=false):array {
        if(PartnerContext::current()==='all'){$rows=[];foreach(PartnerContext::visible() as $partner)$rows+=PartnerContext::run($partner,fn()=>$this->rows($fresh));return $rows;}
        if($this->snapshotPartner!==PartnerContext::current()){
            if($this->snapshotPartner!==null)$this->partnerSnapshots[$this->snapshotPartner]=[$this->snapshot,$this->backingMaterials,$this->failure];
            $this->snapshotPartner=PartnerContext::current();[$this->snapshot,$this->backingMaterials,$this->failure]=$this->partnerSnapshots[$this->snapshotPartner]??[null,[],null];
        }
        if(!$fresh && $this->snapshot!==null)return $this->snapshot;
        try {
            $id=(string)config('services.materials.sheet_id',self::DEFAULT_SHEET_ID);
            if(!preg_match('/^[A-Za-z0-9_-]{20,100}$/',$id))throw new \RuntimeException('Sheet configuration missing');
            if(config('services.materials.authenticated',false)){
                $tab=PartnerContext::tab();
                $parsed=app(MaterialSheetParser::class)->parse(app(ConfigurationSheetReader::class)->read($id,$tab));
                $this->backingMaterials=$parsed['backing_materials'];
                $rows=$parsed['bags'];
                $this->failure=null;
                return $this->snapshot=$rows;
            }
            $response=Http::timeout(12)->connectTimeout(5)->withOptions(['allow_redirects'=>false])->get('https://docs.google.com/spreadsheets/d/'.$id.'/gviz/tq',['tqx'=>'out:csv','sheet'=>PartnerContext::tab()]);
            if(!$response->successful())throw new \RuntimeException('Google Sheets returned HTTP '.$response->status());
            $rows=$this->parse($response->body());
            $this->failure=null;
            return $this->snapshot=$rows;
        } catch(\Throwable $e) {
            $this->snapshot=[];
            $reason=$e instanceof \Illuminate\Http\Client\ConnectionException?'The server could not connect to Google Sheets.':(in_array($e->getMessage(),['Sheet configuration missing','Missing headings','Invalid headings','Invalid bag code or type','Invalid cell','Invalid row','Empty material sheet','Invalid sheet response'],true)?$e->getMessage().'.':(preg_match('/^Google Sheets returned HTTP [0-9]{3}$/',$e->getMessage())?$e->getMessage().'.':'The sheet could not be validated.'));
            $this->failure=$reason.' Check the configured material tab and sharing access, then refresh. New preparation is paused; existing batches are preserved.';
            if($fresh)throw ValidationException::withMessages(['materials'=>$this->failure]);
            return [];
        }
    }
    public function backingColors(string $material):array {
        $this->rows();
        foreach($this->backingMaterials as $row)if(strcasecmp($row['material'],$material)===0)return $row['colors'];
        return [];
    }
    public function error():?string {if(PartnerContext::current()==='all'){$errors=[];foreach(PartnerContext::visible() as $partner){$error=PartnerContext::run($partner,fn()=>$this->error());if($error)$errors[]=PartnerContext::label($partner).': '.$error;}return $errors?implode(' ',$errors):null;}$this->rows();return $this->failure;}
    public function map():array {return array_map(fn($r)=>[$r['front'],$r['back']],$this->rows());}
    public function row(?string $code):?array {return $this->rows()[strtoupper((string)$code)]??null;}
    public function materials():array {
        if(PartnerContext::current()==='all'){$names=[];foreach(PartnerContext::visible() as $partner)$names=[...$names,...PartnerContext::run($partner,fn()=>$this->materials())];$names=array_values(array_unique($names));natcasesort($names);return array_values($names);}
        $names=[];foreach($this->rows() as $row){$names[]=$row['front'];if($row['back']!=='')$names[]=$row['back'];}
        $names=array_values(array_unique($names));natcasesort($names);return array_values($names);
    }
    public function fingerprint():string {return hash('sha256',json_encode($this->rows()));}
    public function parse(string $csv):array {
        if(strlen($csv)>524288 || str_contains($csv,'<html'))throw new \RuntimeException('Invalid sheet response');
        $stream=fopen('php://temp','r+');fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$csv));rewind($stream);
        try {
            $values=[];
            while(($row=fgetcsv($stream,0,',','"',''))!==false){
                if(count($values)>=1000)throw new \RuntimeException('Too many rows');
                $values[]=$row;
            }
            $parsed=app(MaterialSheetParser::class)->parse($values);
            $this->backingMaterials=$parsed['backing_materials'];
            return $parsed['bags'];
        } finally {fclose($stream);}
    }
}
