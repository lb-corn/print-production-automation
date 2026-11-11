<?php
namespace App\Services;

class PartnerContext
{
    public string $partner='lucky';
    public bool $scoped=false;
    public bool $denied=false;
    public ?array $permitted=null;
    public static function get():self {return app(self::class);}
    public static function current():string {return self::get()->partner;}
    public static function label(?string $partner=null):string {return ($partner??self::current())==='all'?'All partners':config('services.partners.'.($partner??self::current()).'.label','Lucky');}
    public static function visible():array {return self::current()==='all'?(self::get()->permitted??self::allowed(auth()->user())):[self::current()];}
    public static function forItem($item,callable $callback):mixed {return self::run($item->partner??'lucky',$callback);}
    public static function enter(string $partner):array {$ctx=self::get();$old=[$ctx->partner,$ctx->scoped,$ctx->denied];$ctx->partner=$partner;$ctx->scoped=true;$ctx->denied=false;return $old;}
    public static function leave(array $old):void {[$ctxPartner,$scoped,$denied]=$old;$ctx=self::get();$ctx->partner=$ctxPartner;$ctx->scoped=$scoped;$ctx->denied=$denied;}
    public static function prefix(?string $partner=null):string {return config('services.partners.'.($partner??self::current()).'.prefix','LB');}
    public static function key(string $key):string {return self::current()==='lucky'?$key:$key.'.'.self::current();}
    public static function shopify(string $key,mixed $default=null):mixed {
        return self::current()==='lucky'?config('services.shopify.'.$key,$default):config('services.partners.'.self::current().'.shopify.'.$key,$default);
    }
    public static function root():string {
        if(config('services.google_drive.factory_root_id'))return app(GoogleDriveService::class)->partnerRoot();
        return self::current()==='lucky'?(string)config('services.google_drive.artwork_root_id'):(string)config('services.partners.'.self::current().'.drive_root_id');
    }
    public static function tab():string {return self::current()==='lucky'?(string)config('services.materials.sheet_tab','Lucky'):(string)config('services.materials.partner_tabs.'.self::current());}
    public static function directSeries():bool {return config('services.partners.'.self::current().'.drive_layout','groups')==='series';}
    public static function artworkRoots():array {return self::directSeries()?[self::label()]:config('services.google_drive.allowed_root_folders',[]);}
    public static function allowed($user):array {
        if(!$user)return [];
        return array_values(array_filter(array_keys(config('services.partners',[])),fn($p)=>$user->allows('partners.'.$p.'.view')));
    }
    public static function run(string $partner,callable $callback):mixed {
        if(!array_key_exists($partner,config('services.partners',[])))throw new \InvalidArgumentException('Unknown production partner.');
        $ctx=self::get();$old=$ctx->partner;$scoped=$ctx->scoped;$denied=$ctx->denied;$ctx->partner=$partner;$ctx->scoped=true;$ctx->denied=false;
        try{return $callback();}finally{$ctx->partner=$old;$ctx->scoped=$scoped;$ctx->denied=$denied;}
    }
}
