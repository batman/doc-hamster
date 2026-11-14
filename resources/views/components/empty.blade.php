{{-- Leerer Zustand (point 213; FRAMEWORK.md "Leerer Zustand"): a quiet dashed block with a small orange symbol, one
     sentence what goes here and, in the slot, a fitting button (or nothing when the sentence is the drop hint).
     <x-empty icon="inbox" :text="__('…')">…button…</x-empty>; inside a <ul> pass tag="li". It is a `.list-empty`, so
     everything that styles or looks for that class keeps working. --}}
@props(['icon' => 'circle', 'text', 'tag' => 'div'])
@php $tag = in_array($tag, ['div', 'li'], true) ? $tag : 'div'; @endphp
<{{ $tag }} {{ $attributes->class('list-empty empty-state') }}>
    <x-icon :name="$icon" />
    <span class="empty-text">{{ $text }}</span>
    @if ($slot->isNotEmpty())
        <div class="empty-action">{{ $slot }}</div>
    @endif
</{{ $tag }}>
