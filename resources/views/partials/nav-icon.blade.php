<svg class="navigation-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
@switch($name)
@case('overview')<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>@break
@case('verification')<rect x="3" y="3" width="18" height="18" rx="4"/><path d="m7 12 3 3 7-7"/>@break
@case('queue')<path d="M7 8V3h10v5M7 16H4V8h16v8h-3"/><path d="M7 13h10v8H7zM17 10h.01"/>@break
@case('batches')<path d="m3 7 9-4 9 4-9 4-9-4Zm0 5 9 4 9-4M3 17l9 4 9-4"/>@break
@endswitch
</svg>
