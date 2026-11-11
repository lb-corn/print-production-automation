<?php
namespace App\Models\Concerns;
use App\Services\PartnerContext;
trait BelongsToPartner
{
    protected static function bootBelongsToPartner():void {
        static::addGlobalScope('partner',function($query){$ctx=PartnerContext::get();if($ctx->scoped && $ctx->denied){$query->whereRaw('1=0');return;}if($ctx->scoped)$query->where($query->getModel()->qualifyColumn('partner'),$ctx->partner);});
        static::creating(function($model){$model->partner??=PartnerContext::current();});
    }
}
