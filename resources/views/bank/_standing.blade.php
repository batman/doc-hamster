{{-- A325: in the document detail, the Dauerbeleg rules of this document ("Dauerbeleg für: name, amount, monatlich")
     with a button to end one (no more links; the existing ones stay). Included from documents._detail. --}}
@php
    $standingRules = Gate::allows('banking.import')
        ? \App\Domain\Banking\StandingDocument::query()->where('document_id', $document->id)->where('active', true)->oldest()->get()
        : collect();
@endphp
@foreach ($standingRules as $rule)
    <div class="standing-line">
        <x-icon name="calendar-repeat" />
        <span>{{ __('bank.standing_for', ['name' => \App\Domain\Banking\StandingDocuments::label($rule)]) }}, {{ \App\Domain\Banking\Transaction::signed($rule->signedAmount(), $rule->currency) }}@if ($rule->interval), {{ __('bank.interval.'.$rule->interval) }}@endif</span>
        <form method="post" action="{{ route('bank.standing.end', $rule->id) }}">
            @csrf
            <button type="submit" class="button-quiet button-small">{{ __('bank.standing_end') }}</button>
        </form>
    </div>
@endforeach
