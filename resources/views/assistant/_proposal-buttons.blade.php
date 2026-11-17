{{-- ✓ Übernehmen and the bin Ablehnen as icon buttons (point 5), a duplicate also "Das andere behalten"; used on the
     Empfehlungskachel and in the head of a document or booking window opened from it (A62). --}}
<form method="post" action="{{ route('proposals.accept', $proposal->id) }}" data-proposal-action="{{ $proposal->id }}" @if ($head ?? false) data-proposal-head="{{ $proposal->id }}" @endif>
    @csrf
    <button type="submit" class="icon-button" aria-label="{{ __('proposals.accept') }}" data-tooltip="{{ __('proposals.accept') }}"><x-icon name="check" /></button>
</form>
@if ($proposal->kind === \App\Domain\Assistant\Proposal::DUPLICATE)
    <form method="post" action="{{ route('proposals.accept', $proposal->id) }}" data-proposal-action="{{ $proposal->id }}" @if ($head ?? false) data-proposal-head="{{ $proposal->id }}" @endif>
        @csrf
        <input type="hidden" name="swap" value="1">
        <button type="submit" class="icon-button" aria-label="{{ __('proposals.swap') }}" data-tooltip="{{ __('proposals.swap') }}"><x-icon name="arrows-left-right" /></button>
    </form>
@endif
<form method="post" action="{{ route('proposals.reject', $proposal->id) }}" data-proposal-action="{{ $proposal->id }}" @if ($head ?? false) data-proposal-head="{{ $proposal->id }}" @endif>
    @csrf
    <button type="submit" class="icon-button" aria-label="{{ __('proposals.reject') }}" data-tooltip="{{ __('proposals.reject') }}"><x-icon name="trash" /></button>
</form>
