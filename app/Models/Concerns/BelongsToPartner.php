<?php
namespace App\Models\Concerns;
use App\Services\PartnerContext;
trait BelongsToPartner
{
    protected static function bootBelongsToPartner():void {
        static::addGlobalScope('partner',function($query){$ctx=PartnerContext::get();if($ctx->scoped && $ctx->denied){$query->whereRaw('1=0');return;}if($ctx->scoped)$query->whereIn($query->getModel()->qualifyColumn('partner'),PartnerContext::visible());});
        static::creating(function($model){$model->partner??=PartnerContext::current();});
    }
}
