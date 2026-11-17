{{-- A62: a document or booking window opened from a Vorschlag (?proposal=<id>) shows ✓ / bin of that proposal in its
     toolbar, so the owner decides right after comparing; gone once decided (modules/proposals.js). --}}
@php
    $headProposal = is_string(request()->query('proposal')) && Gate::allows('assistant.manage')
        ? \App\Domain\Assistant\Proposal::query()->where('status', \App\Domain\Assistant\Proposal::OPEN)->whereKey(request()->query('proposal'))->first()
        : null;
@endphp
@if ($headProposal)
    @include('assistant._proposal-buttons', ['proposal' => $headProposal, 'head' => true])
@endif
