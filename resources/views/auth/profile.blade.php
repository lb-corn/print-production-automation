<x-layouts.app heading="Profile" subheading="Your workspace account">
    <section class="panel profile-panel" aria-labelledby="profile-heading">
        <div class="profile-heading">
            <span class="avatar" aria-hidden="true">{{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}</span>
            <div><h2 id="profile-heading">{{ auth()->user()->name }}</h2><p>Account details</p></div>
        </div>
        <dl class="profile-details">
            <div><dt>Name</dt><dd>{{ auth()->user()->name }}</dd></div>
            <div><dt>Email</dt><dd>{{ auth()->user()->email }}</dd></div>
            <div><dt>Role</dt><dd>{{ ucfirst(auth()->user()->role ?? 'operator') }}</dd></div>
        </dl>
    </section>
</x-layouts.app>
