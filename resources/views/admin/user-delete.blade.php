<x-layouts.app heading="Delete user" subheading="Admin controls">
    <form class="panel admin-form" method="post" action="{{ route('admin.users.destroy', $user) }}">
        @csrf @method('DELETE')
        <h2>Delete {{ $user->name }}?</h2>
        <p>{{ $user->email }}</p>
        <div class="reset-warning">This permanently deletes the account and prevents further access. Production history and signed print records remain. You can deactivate the user instead if you may need to restore their access.</div>
        <label for="delete-password">Your admin password</label><input id="delete-password" type="password" name="password" autocomplete="current-password" required>
        <label for="delete-confirmation">Type {{ $user->email }} to confirm</label><input id="delete-confirmation" name="confirmation" autocomplete="off" required>
        <div class="admin-actions"><a href="{{ route('admin.users') }}">Cancel</a><button class="danger-button" type="submit">Delete user permanently</button></div>
    </form>
</x-layouts.app>
