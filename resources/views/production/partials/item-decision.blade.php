@if($item->ready_for_print)
    <span class="ok">APPROVED - READY FOR PRINT</span>
@elseif($item->decision?->decision === 'WRONG' && $item->approval_current)
    <span class="bad">WRONG ARTWORK</span>
@elseif($item->decision && !$item->approval_current)
    <span class="warn">ARTWORK CHANGED - RE-VERIFY</span>
@else
    NOT VERIFIED
@endif
