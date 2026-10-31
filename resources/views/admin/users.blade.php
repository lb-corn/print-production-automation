<x-layouts.app heading="Users" subheading="Manage portal access">
    <div class="admin-toolbar"><p>Admins manage users and reset production data. Normal users operate the production workspace.</p><a class="primary" href="{{ route('admin.users.create') }}">Create user</a></div>
    @unless(\App\Models\User::where('email', 'mike@luckycornhole.com')->exists())
        <div class="admin-notice">Set up Mike as a normal user. <a href="{{ route('admin.users.create', ['preset' => 'mike']) }}">Create Mike’s account →</a></div>
    @endunless
    <section class="panel admin-user-list" aria-label="Portal users">
        <div class="admin-user-columns" aria-hidden="true"><span>User</span><span>Access</span><span>Status</span><span>Actions</span></div>
        @foreach($users as $user)
            <article class="admin-user-row">
                <div><strong>{{ $user->name }}</strong><span>{{ $user->email }}</span></div>
                <div class="user-badges"><span class="admin-role">{{ $user->role === 'admin' ? 'Admin' : ($user->role === 'demo' ? 'Demo' : 'Normal user') }}</span></div>
                <span class="user-status {{ $user->is_active ? '' : 'is-inactive' }}"><i aria-hidden="true"></i>{{ $user->is_active ? 'Active' : 'Inactive' }}</span>
                <div class="user-actions">

                    @if($user->role !== 'admin')
                        <form method="post" action="{{ route('admin.users.status', $user) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $user->is_active ? '0' : '1' }}"><button class="{{ $user->is_active ? 'user-deactivate' : 'user-activate' }}" type="submit" title="{{ $user->is_active ? 'Deactivate' : 'Activate' }} user" aria-label="{{ $user->is_active ? 'Deactivate' : 'Activate' }} {{ $user->name }}">@include('partials.user-action-icon', ['action' => 'status'])</button></form>
                        <a class="user-delete-link" href="{{ route('admin.users.delete', $user) }}" data-delete-user data-user-name="{{ $user->name }}" data-user-email="{{ $user->email }}" data-delete-action="{{ route('admin.users.destroy', $user) }}" title="Delete user" aria-label="Delete {{ $user->name }}">@include('partials.user-action-icon', ['action' => 'delete'])</a>
                    @endif
                    <a href="{{ route('admin.users.edit', $user) }}" title="Edit user" aria-label="Edit {{ $user->name }}">@include('partials.user-action-icon', ['action' => 'edit'])</a>
                </div>
            </article>
        @endforeach
    </section>
    {{ $users->links() }}
    <dialog class="user-delete-dialog" data-delete-dialog aria-labelledby="delete-dialog-title" aria-describedby="delete-dialog-description">
        <form class="admin-form" method="post" data-delete-form>
            @csrf @method('DELETE')
            <div class="delete-dialog-heading"><h2 id="delete-dialog-title">Delete user?</h2><button type="button" data-close-delete aria-label="Close confirmation">×</button></div>
            <p class="delete-target" data-delete-target></p>
            <p id="delete-dialog-description">This permanently removes the account and blocks access. Production history stays intact. Deactivate the user instead to allow access to be restored later.</p>
            <label for="modal-delete-password">Your admin password</label><input id="modal-delete-password" type="password" name="password" autocomplete="current-password" required>
            <label for="modal-delete-email">Type the user’s email to confirm</label><input id="modal-delete-email" name="confirmation" autocomplete="off" required>
            <div class="admin-actions"><button class="dialog-cancel" type="button" data-close-delete autofocus>Cancel</button><button class="danger-button" type="submit">Delete user permanently</button></div>
        </form>
    </dialog>
</x-layouts.app>
