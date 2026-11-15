{{-- Point 233, A395, A396: pins a column or a note to Home as its own widget, or takes it off (notes.js, saves at once).
     $type = note_column | note, $id, $pinned (map of layout keys). --}}
@php
    $on = isset($pinned[\App\Domain\Dashboard\DashboardLayout::key($type, $id)]);
    $label = __($on ? 'dashboard.note_unpin' : 'dashboard.note_pin');
@endphp
<button type="button" class="note-tool note-pin" data-note-pin="{{ route('dashboard.notes.pin') }}" data-pin-type="{{ $type }}" data-pin-id="{{ $id }}"
        aria-pressed="{{ $on ? 'true' : 'false' }}" aria-label="{{ $label }}" data-tooltip="{{ $label }}"
        data-label-on="{{ __('dashboard.note_unpin') }}" data-label-off="{{ __('dashboard.note_pin') }}" data-failed="{{ __('dashboard.failed') }}"><x-icon name="pin" /></button>
