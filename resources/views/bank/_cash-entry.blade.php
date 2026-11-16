{{-- Point 190: a cash entry typed in by hand has row actions (a row never has a ⋯ menu): Bearbeiten (dialog) and
     Löschen (confirm dialog). Imported bookings never get them. --}}
<span class="row-actions">
    <button type="button" class="control-button" data-dialog-open="cash-edit-{{ $entry->id }}" aria-label="{{ __('bank.cash_edit') }}" data-tooltip="{{ __('bank.cash_edit') }}"><x-icon name="pencil" /></button>
    <button type="button" class="control-button control-danger" data-dialog-open="cash-delete-{{ $entry->id }}" aria-label="{{ __('bank.cash_delete') }}" data-tooltip="{{ __('bank.cash_delete') }}"><x-icon name="trash" /></button>
</span>
<div class="dialog-backdrop" data-dialog="cash-edit-{{ $entry->id }}" hidden>
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="cash-edit-{{ $entry->id }}-title">
        <h2 id="cash-edit-{{ $entry->id }}-title">{{ __('bank.cash_edit_title') }}</h2>
        <form method="post" action="{{ route('bank.cash.update', $entry->id) }}" data-bank-action>
            @csrf
            @include('bank._cash-fields', ['prefix' => 'cash-edit-'.$entry->id, 'cashEntry' => $entry])
            <div class="dialog-actions">
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
                <button type="submit" class="button-danger">{{ __('bank.cash_delete') }}</button>
            </div>
        </form>
    </div>
</div>
