<x-layouts.app heading="Users" subheading="Manage portal access">
    <div class="admin-toolbar"><p>Admins manage users and reset production data. Normal users operate the production workspace.</p><a class="primary" href="{{ route('admin.users.create') }}">Create user</a></div>
    @unless(\App\Models\User::where('email', 'mike@luckycornhole.com')->exists())
        <div class="admin-notice">Set up Mike as a normal user. <a href="{{ route('admin.users.create', ['preset' => 'mike']) }}">Create Mike’s account →</a></div>
    @endunless
    <section class="panel admin-user-list" aria-label="Portal users">
        @foreach($users as $user)
            <article class="admin-user-row"><div><strong>{{ $user->name }}</strong><span>{{ $user->email }}</span></div><span class="admin-role">{{ $user->role === 'admin' ? 'Admin' : 'Normal user' }}</span><a href="{{ route('admin.users.edit', $user) }}" aria-label="Edit {{ $user->name }}">Edit →</a></article>
        @endforeach
    </section>
    {{ $users->links() }}
</x-layouts.app>
