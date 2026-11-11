@php
    $companyChoices=\App\Services\PartnerContext::allowed(auth()->user());
    $companyChoices=str_starts_with(request()->route()?->getName()??'','admin.')?$companyChoices:array_merge(['all'],$companyChoices);
@endphp
<label class="company-field" for="company-view"><span class="company-field-title">Company</span>
<select id="company-view" name="partner" @if($companyAutoSubmit??true)onchange="this.form.submit()"@endif>
@foreach($companyChoices as $company)<option value="{{$company}}" @selected(\App\Services\PartnerContext::current()===$company)>{{$company==='all'?'All companies':\App\Services\PartnerContext::label($company)}}</option>@endforeach
</select></label>
