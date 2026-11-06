<div class="role-manager">
<p class="role-manager-intro">Admin has full access. Demo is read only. Customize other roles below.</p>
<div class="role-list">
@foreach($roles as $role)
<details class="panel role-card" name="role-editor">
<summary><span class="role-card-chevron">@include('partials.nav-icon',['name'=>'chevron'])</span><span class="role-card-name">{{$role->name}}</span><span class="role-permission-count">{{count($role->permissions)}} permissions</span></summary>
@if($role->slug==='demo')
<p class="role-readonly-note">Demo is permanently read only.</p>
@else
<form method="post" action="{{route('admin.roles.update',$role)}}" class="admin-form role-editor">
@csrf
@method('PUT')
@include('admin.role-fields',['selected'=>$role->permissions,'roleName'=>$role->name])
<button class="primary" type="submit">Save role</button>
</form>
@endif
</details>
@endforeach
</div>
<details class="panel role-card role-create" name="role-editor">
<summary><span class="role-card-chevron">@include('partials.nav-icon',['name'=>'chevron'])</span><span class="role-card-name">Create role</span><span class="role-create-plus" aria-hidden="true">+</span></summary>
<form method="post" action="{{route('admin.roles.store')}}" class="admin-form role-editor">
@csrf
@include('admin.role-fields',['selected'=>[],'roleName'=>''])
<button class="primary" type="submit">Create role</button>
</form>
</details>
@if(isset($audits) && $audits->isNotEmpty())
<section class="panel role-audit"><h3>Recent access changes</h3><div class="access-audit-list">
@foreach($audits as $audit)
<p><strong>{{$audit->actor_name ?? 'Deleted user'}}</strong> &middot; {{$audit->action}} &middot; {{$audit->subject_type}} {{$audit->subject_id}}<br><small>{{$audit->created_at}}</small></p>
@endforeach
</div></section>
@endif
</div>
