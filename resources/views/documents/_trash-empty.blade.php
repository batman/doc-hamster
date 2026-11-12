{{-- "Papierkorb leeren" (A136): the confirm names the count; without JavaScript a plain form post. --}}
<div class="dialog-backdrop" data-dialog="trash-empty" hidden>
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="trash-empty-title">
        <h2 id="trash-empty-title">{{ __('documents.bulk.empty_title') }}</h2>
        <p>{{ trans_choice('documents.bulk.empty_text', $count, ['count' => $count]) }}</p>
        <div class="dialog-actions">
            <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.bulk.cancel') }}</button>
            <form method="post" action="{{ route('documents.trash.empty') }}" data-bulk-form>
                @csrf
                <button type="submit" class="button-danger">{{ __('documents.bulk.empty') }}</button>
            </form>
        </div>
    </div>
</div>
