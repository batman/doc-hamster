{{-- Point 190: the ⋯ menu of a cash entry typed in by hand – Bearbeiten (dialog) and Löschen (confirm dialog).
     Imported bookings never get it. --}}
<x-more-menu :label="__('bank.cash_menu')">
    <button type="button" class="menu-item" data-dialog-open="cash-edit-{{ $entry->id }}"><x-icon name="pencil" /><span>{{ __('bank.cash_edit') }}</span></button>
    <hr class="menu-separator">
    <button type="button" class="menu-item menu-danger" data-dialog-open="cash-delete-{{ $entry->id }}"><x-icon name="trash" /><span>{{ __('bank.cash_delete') }}</span></button>
</x-more-menu>
<div class="dialog-backdrop" data-dialog="cash-edit-{{ $entry->id }}" hidden>
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="cash-edit-{{ $entry->id }}-title">
        <h2 id="cash-edit-{{ $entry->id }}-title">{{ __('bank.cash_edit_title') }}</h2>
        <form method="post" action="{{ route('bank.cash.update', $entry->id) }}" data-bank-action>
            @csrf
            @include('bank._cash-fields', ['prefix' => 'cash-edit-'.$entry->id, 'cashEntry' => $entry])
            <div class="dialog-actions">
                <button type="button" class="button-quiet" data-dialog-close>{{ __('bank.cancel') }}</button>
                <button type="submit">{{ __('bank.cash_save') }}</button>
            </div>
        </form>
    </div>
</div>
<div class="dialog-backdrop" data-dialog="cash-delete-{{ $entry->id }}" hidden>
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="cash-delete-{{ $entry->id }}-title">
        <h2 id="cash-delete-{{ $entry->id }}-title">{{ __('bank.cash_delete_title') }}</h2>
        <p>{{ __('bank.cash_delete_text', ['name' => $entry->purpose ?? '–', 'amount' => $entry->signedAmount()]) }}</p>
        <form method="post" action="{{ route('bank.cash.destroy', $entry->id) }}" data-bank-action>
            @csrf
            <div class="dialog-actions">
                <button type="button" class="button-quiet" data-dialog-close>{{ __('bank.cancel') }}</button>
                <button type="submit" class="button-danger">{{ __('bank.cash_delete') }}</button>
            </div>
        </form>
    </div>
</div>
