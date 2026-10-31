<x-layouts.app heading="Users" subheading="Manage portal access">
    <div class="admin-toolbar"><p>Admins manage users and reset production data. Normal users operate the production workspace.</p><a class="primary" href="{{ route('admin.users.create') }}">Create user</a></div>
    @unless(\App\Models\User::where('email', 'mike@luckycornhole.com')->exists())
        <div class="admin-notice">Set up Mike as a normal user. <a href="{{ route('admin.users.create', ['preset' => 'mike']) }}">Create Mike’s account →</a></div>
    @endunless
    <section class="panel admin-user-list" aria-label="Portal users">
        @foreach($users as $user)
            <article class="admin-user-row">
                <div><strong>{{ $user->name }}</strong><span>{{ $user->email }}</span></div>
                <div class="user-badges"><span class="admin-role">{{ $user->role === 'admin' ? 'Admin' : 'Normal user' }}</span><span class="user-status {{ $user->is_active ? '' : 'is-inactive' }}">{{ $user->is_active ? 'Active' : 'Inactive' }}</span></div>
                <div class="user-actions">
                    <a href="{{ route('admin.users.edit', $user) }}" aria-label="Edit {{ $user->name }}">Edit</a>
                    @if($user->role !== 'admin')
                        <form method="post" action="{{ route('admin.users.status', $user) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $user->is_active ? '0' : '1' }}"><button type="submit" aria-label="{{ $user->is_active ? 'Deactivate' : 'Activate' }} {{ $user->name }}">{{ $user->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                        <a class="user-delete-link" href="{{ route('admin.users.delete', $user) }}" aria-label="Delete {{ $user->name }}">Delete</a>
                    @endif
                </div>
            </article>
        @endforeach
    </section>
    {{ $users->links() }}
</x-layouts.app>
