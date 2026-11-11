<div class="material-totals">
 <div><span>To print</span><strong>{{number_format($summary['printable_yards'],3)}} <small>yd</small></strong></div>
 <div><span>Unprinted backing · stock only</span><strong>{{number_format($summary['nonprint_yards'],3)}} <small>yd</small></strong></div>
 <div class="material-grand-total"><span>Total fabric stock</span><strong>{{number_format($summary['total_yards'],3)}} <small>yd</small></strong></div>
</div>
<div class="material-breakdown material-live-table"><h3>Material requirements</h3><p class="muted">{{\App\Services\PartnerContext::label()}} materials. Only printed sides are queued to Epson.</p><table class="material-table"><thead><tr><th>Material</th><th>Print sides</th><th>Print yd</th><th>Backing yd</th></tr></thead><tbody>
@forelse($summary['printable_materials'] as $name=>$material)<tr><th scope="row">{{$name}}</th><td>{{$material['sides']}}</td><td>{{number_format($material['yards'],3)}}</td><td>{{number_format($summary['nonprint_materials'][$name]['yards']??0,3)}}</td></tr>@empty<tr><td colspan="4">Material settings unavailable</td></tr>@endforelse
</tbody></table></div>
<details class="material-explanation"><summary>How material is calculated</summary><p class="material-basis">{{number_format(\App\Services\ProductionService::YARDS,3)}} yd per side per set. Front/back assignments and single/double type come from the {{\App\Services\PartnerContext::label()}} sheet. Single-sided backs are stock requirements only. Existing queued batches keep their approved material snapshots.</p></details>
