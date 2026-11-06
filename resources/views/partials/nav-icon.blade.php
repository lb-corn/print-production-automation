<svg class="navigation-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
@switch($name)
@case('overview')<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>@break
@case('verification')<rect x="3" y="3" width="18" height="18" rx="4"/><path d="m7 12 3 3 7-7"/>@break
@case('queue')<path d="M7 8V3h10v5M7 16H4V8h16v8h-3"/><path d="M7 13h10v8H7zM17 10h.01"/>@break
@case('batches')<path d="m3 7 9-4 9 4-9 4-9-4Zm0 5 9 4 9-4M3 17l9 4 9-4"/>@break
@case('bag-production')<path d="M7 4h10l2 16H5L7 4Z"/><path d="M9 4V2h6v2M8 9h8M8 15c2-2 6-2 8 0"/>@break
@case('apparel-production')<path d="m8 4-5 3 3 5 2-1v10h8V11l2 1 3-5-5-3c0 4-8 4-8 0Z"/>@break
@case('admin')<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m9 12 2 2 4-4"/>@break
@case('roles')<circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M17 5a3 3 0 0 1 0 6M18 15a5 5 0 0 1 3 5"/>@break
@case('settings')<path d="M4 7h16M4 17h16"/><circle cx="8" cy="7" r="3"/><circle cx="16" cy="17" r="3"/>@break
@case('chevron')<path d="m9 6 6 6-6 6"/>@break
@endswitch
</svg>
