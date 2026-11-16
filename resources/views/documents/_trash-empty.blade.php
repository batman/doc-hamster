{{-- "Papierkorb leeren" (A136, point 207): the confirm names the count and the undo period; without JavaScript a plain form post. --}}
@php($undoDays = \App\Domain\Documents\TrashSettings::of(app(\App\Domain\Org\CurrentOrg::class)->organization())['undo'])
<div class="dialog-backdrop" data-dialog="trash-empty" hidden>
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="trash-empty-title">
        <h2 id="trash-empty-title">{{ __('documents.bulk.empty_title') }}</h2>
        <p>{{ trans_choice('documents.bulk.empty_text', $count, ['count' => $count, 'days' => $undoDays]) }}</p>
        <div class="dialog-actions">
            <form method="post" action="{{ route('documents.trash.empty') }}" data-bulk-form>
                @csrf
                <button type="submit" class="button-danger">{{ __('documents.bulk.empty') }}</button>
            </form>
        </div>
    </div>
</div>
