<?php
namespace App\Services;

use App\Models\{JerseyOrder,PrintBatch};

class DashboardCharts
{
    private function companies(array $counts):array {
        return array_map(fn($partner)=>['key'=>$partner,'label'=>PartnerContext::label($partner),'value'=>(int)($counts[$partner]??0),'color'=>$partner==='vibe'?'#7162c4':'#087f83'],PartnerContext::visible());
    }
    private function trend($records,string $date):array {
        $months=[];$start=now()->startOfMonth()->subMonths(5);
        for($i=0;$i<6;$i++){$month=$start->copy()->addMonths($i);$months[$month->format('Y-m')]=['label'=>$month->format('M'),'value'=>0];}
        foreach($records as $record){$value=$record->$date;if(!$value)continue;$key=\Illuminate\Support\Carbon::parse($value)->format('Y-m');if(isset($months[$key]))$months[$key]['value']++;}
        return array_values($months);
    }
    public function apparel(array $summary):array {
        $states=[['label'=>'Waiting','value'=>$summary['unfulfilled'],'color'=>'#e6a23c'],['label'=>'In progress','value'=>$summary['partially_fulfilled'],'color'=>'#087f83'],['label'=>'Fulfilled','value'=>$summary['fulfilled'],'color'=>'#39a47c']];
        $other=$summary['total']-array_sum(array_column($states,'value'));
        if($other>0)$states[]=['label'=>'Archived / other','value'=>$other,'color'=>'#a5b5c0'];
        $counts=JerseyOrder::selectRaw('partner, COUNT(*) as total')->groupBy('partner')->pluck('total','partner')->all();
        return ['states'=>$states,'companies'=>$this->companies($counts),'companyTitle'=>'Apparel by company','companyHint'=>'Imported order lines, including fulfilled orders','trend'=>$this->trend(JerseyOrder::where('ordered_at','>=',now()->startOfMonth()->subMonths(5))->get(['ordered_at']),'ordered_at'),'trendTitle'=>'Orders over time','trendHint'=>'Apparel order lines · last 6 months','total'=>$summary['total'],'unit'=>'imported lines'];
    }
    public function bags($items,ProductionService $service):array {
        $index=$service->batchIndex();$counts=['Needs review'=>0,'Ready for Print'=>0,'In print'=>0,'Completed'=>0,'Needs attention'=>0];
        foreach($items as $item){$batch=$index[$item->line_item_id]??null;$state=$batch?match($batch['status']){'PRINTED'=>'Completed','FAILED'=>'Needs attention',default=>'In print'}:($service->ready($item)?'Ready for Print':'Needs review');$counts[$state]++;}
        $colors=['#e6a23c','#087f83','#7162c4','#39a47c','#c35965'];$states=[];$i=0;foreach($counts as $label=>$value){$color=$colors[$i++];if($label==='Needs attention' && !$value)continue;$states[]=['label'=>$label,'value'=>$value,'color'=>$color];}
        return ['states'=>$states,'companies'=>$this->companies($items->countBy('partner')->all()),'companyTitle'=>'Bags by company','companyHint'=>'Current Shopify order lines','trend'=>$this->trend(PrintBatch::where('created_at','>=',now()->startOfMonth()->subMonths(5))->where('status','!=','CANCELLED')->get(['created_at']),'created_at'),'trendTitle'=>'Print activity','trendHint'=>'Batches created · last 6 months','total'=>$items->count(),'unit'=>'current lines'];
    }
}
