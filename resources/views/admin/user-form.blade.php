<x-layouts.app :heading="$user->exists ? 'Edit user' : 'Create user'" subheading="Portal account and access">
    <form class="panel admin-form" method="post" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}">
        @csrf
        @if($user->exists) @method('PUT') @endif
        <label for="user-name">Name</label><input id="user-name" name="name" value="{{ old('name', $user->name) }}" required maxlength="255" autocomplete="name">
        <label for="user-email">Email</label><input id="user-email" type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="255" autocomplete="off">
        <label for="user-role">Access</label><select id="user-role" name="role" aria-describedby="role-help" @disabled(!auth()->user()->allows('admin.roles.edit'))>@if(auth()->user()->role==='admin')<option value="admin" @selected(old('role', $user->role) === 'admin')>Admin</option>@endif
@foreach(\App\Models\Role::orderBy('name')->get() as $role)<option value="{{$role->slug}}" @selected(old('role',$user->role)===$role->slug)>{{$role->name}}</option>@endforeach</select>
@if(!auth()->user()->allows('admin.roles.edit'))<input type="hidden" name="role" value="{{$user->role}}">@endif
        <p id="role-help" class="admin-hint">User management and role assignment require their respective permissions. Only Admin can grant Admin access.</p>
        <label for="user-password">{{ $user->exists ? 'New password (optional)' : 'Password' }}</label><input id="user-password" type="password" name="password" minlength="12" autocomplete="new-password" @required(!$user->exists) aria-describedby="password-help">
        <p id="password-help" class="admin-hint">At least 12 characters. {{ $user->exists ? 'Leave both password fields blank to keep the current password.' : 'Share the password securely with the user.' }}</p>
        <label for="user-password-confirm">Confirm password</label><input id="user-password-confirm" type="password" name="password_confirmation" minlength="12" autocomplete="new-password" @required(!$user->exists)>
        <div class="admin-actions"><a href="{{ route('admin.users') }}">Cancel</a><button class="primary" type="submit">{{ $user->exists ? 'Save changes' : 'Create user' }}</button></div>
    </form>
</x-layouts.app>
