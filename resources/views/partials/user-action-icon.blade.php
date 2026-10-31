<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @if($action === 'edit')
        <path d="m15 5 4 4M4 20l4-1L20 7a2.8 2.8 0 0 0-4-4L4 15l-1 6 5-2"/>
    @elseif($action === 'delete')
        <path d="M4 7h16M9 7V4h6v3M6 7l1 14h10l1-14M10 11v6M14 11v6"/>
    @else
        <path d="M12 3v9M7 5a8 8 0 1 0 10 0"/>
    @endif
</svg>
