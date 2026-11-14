@props(['folders' => 0, 'documents' => 0, 'total' => null])
@php
    // Inhaltszähler (point 161 / F5 variant D): "6 [folder] · 3 [document] (50 [document] insgesamt)" – zero parts left out,
    // the bracket only when the total differs from the own count, "Leer" when there is nothing at all. Each part carries
    // its words as tooltip and for screen readers; the symbols are decoration.
    $total ??= $documents;
    $parts = [];
    if ($folders > 0) {
        $parts[] = [$folders, 'folder', trans_choice('folders.stat_folders', $folders, ['count' => $folders])];
    }
    if ($documents > 0) {
        $parts[] = [$documents, 'file-text', trans_choice('folders.stat_documents', $documents, ['count' => $documents])];
    }
@endphp
<small {{ $attributes->class('row-stats') }}>@if ($parts === [] && $total === 0){{ __('folders.stat_empty') }}@else
@foreach ($parts as [$n, $icon, $words])@if (! $loop->first)<span class="row-stat-sep" aria-hidden="true">·</span>@endif<span class="row-stat" data-tooltip="{{ $words }}"><span aria-hidden="true">{{ $n }}<x-icon :name="$icon" /></span><span class="visually-hidden">{{ $words }}</span></span>@endforeach
@if ($total !== $documents)@php $words = trans_choice('folders.stat_total', $total, ['count' => $total]); @endphp<span class="row-stat is-total" data-tooltip="{{ $words }}"><span aria-hidden="true">({{ $total }}<x-icon name="file-text" /> {{ __('folders.stat_total_word') }})</span><span class="visually-hidden">{{ $words }}</span></span>@endif
@endif</small>
