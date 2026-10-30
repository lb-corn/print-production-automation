<div class="material-totals">
    <div><span>Printed fabric</span><strong>{{number_format($summary['printable_yards'],3)}} <small>yd</small></strong></div>
    <div><span>Plain backing</span><strong>{{number_format($summary['nonprint_yards'],3)}} <small>yd</small></strong></div>
    <div class="material-grand-total"><span>Total fabric</span><strong>{{number_format($summary['total_yards'],3)}} <small>yd</small></strong></div>
</div>
<div class="material-groups">
    @foreach(['printable_materials'=>'Printable fabric','nonprint_materials'=>'Plain backside fabric'] as $group=>$heading)
    <section class="material-breakdown"><h3>{{$heading}}</h3>
        <table class="material-table"><thead><tr><th scope="col">Material</th><th scope="col">Side sets</th><th scope="col">Yards</th></tr></thead><tbody>
        @forelse($summary[$group] as $name=>$material)
            <tr><th scope="row">{{$name}}</th><td>{{$material['sides']}}</td><td>{{number_format($material['yards'],3)}}</td></tr>
        @empty<tr><td colspan="3" class="material-none">None required</td></tr>@endforelse
        </tbody></table>
    </section>
    @endforeach
</div>
<details class="material-explanation"><summary>How material is calculated</summary><p class="material-basis">{{number_format(\App\Services\ProductionService::YARDS,3)}} yd per side per set. Each set needs a front and back; single-sided sets print only the front. Totals exclude additional waste allowance.</p></details>
