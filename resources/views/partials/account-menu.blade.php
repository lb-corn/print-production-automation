<div class="workspace-tools">
    <details class="account-menu" data-account-menu>
        <summary aria-label="Account menu">
            <span class="avatar" aria-hidden="true">{{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}</span>
            <span class="account-trigger-label">Account</span>
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg>
        </summary>
        <div class="account-dropdown">
            <div class="account-identity"><strong>{{ auth()->user()->name }}</strong><span>{{ auth()->user()->email }}</span></div>
            <nav aria-label="Account navigation">
                <a href="{{ route('profile') }}" @if(request()->routeIs('profile')) aria-current="page" @endif>Profile</a>
                <a href="{{ route('docs') }}" @if(request()->routeIs('docs')) aria-current="page" @endif>Docs <span>Quick guide</span></a>
            </nav>
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Sign out <span aria-hidden="true">→</span></button>
            </form>
        </div>
    </details>
</div>
