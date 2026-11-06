<label class="role-name-field"><span>Role name</span><input name="name" value="{{$roleName}}" required maxlength="80"></label>
@foreach(['bags'=>'Bag Production','apparel'=>'Apparel Production','admin'=>'Administration'] as $area=>$areaLabel)
<fieldset class="permission-area"><legend>{{$areaLabel}}</legend>
<div class="permission-grid">
@foreach($permissions as $key=>$label)
@if(str_starts_with($key,$area.'.'))
<label class="permission-option"><input type="checkbox" name="permissions[]" value="{{$key}}" @checked(in_array($key,$selected,true)) @disabled(!auth()->user()->allows($key))><span>{{$label}}</span></label>
@endif
@endforeach
</div>
</fieldset>
@endforeach
<p class="admin-hint">Edit and approval access include matching view access. Production permissions include the area overview. You can delegate only permissions you hold.</p>
