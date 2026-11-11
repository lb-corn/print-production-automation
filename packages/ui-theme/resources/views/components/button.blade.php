@props(['variant'=>'primary'])
@php $buttonClass=in_array($variant,['primary','chip','approve','reject'],true)?$variant:'primary'; @endphp
<button {{ $attributes->class([$buttonClass])->merge(['type'=>'button']) }}>{{ $slot }}</button>
