{{-- One booking as a window: its values, status (A270), the linked documents with the matcher's suggestions to confirm or
     reject (A268), the sums of an n:m link (A271) and "Dokument zuordnen" with the best candidates first plus the search
     in the toolbar (A269). Forms with data-bank-action are sent by modules/bank.js (toast, bank windows reload). --}}
@php
    $state = $transaction->status();
    $statusClass = ['open' => 'pill-warning', 'suggested' => 'pill-new', 'linked' => 'pill-ok', 'none' => ''];
    $money = static fn (int $minor): string => \App\Domain\Banking\Transaction::money($minor, $transaction->currency);
    $why = static fn (array $reasons): string => __('bank.why', ['reasons' => implode(', ', array_map(static fn (string $key): string => __('bank.reasons.'.$key), array_keys($reasons)))]);
@endphp
<x-window :title="$transaction->formattedAmount().' · '.($transaction->counterparty ?? __('bank.transaction'))" icon="receipt" kind="bank-transaction"
          min="28rem" optimal="38rem" max="56rem" priority="2"
          :url="route('bank.show', array_filter([$transaction->id, 'q' => $search !== '' ? $search : null]))" :close="route('bank.account', $transaction->bank_account_id)"
          :search="__('bank.candidates_search')">
    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="card">
        <h2 class="card-head"><x-icon name="receipt" />{{ __('bank.transaction') }} <span class="pill {{ $statusClass[$state] }}">{{ __('bank.status.'.$state) }}</span></h2>
        <ul class="prop-list">
            <li class="prop-row"><span class="prop-name">{{ __('bank.columns.date') }}</span><span class="prop-value">{{ $transaction->booking_date->format('d.m.Y') }}</span></li>
            <li class="prop-row"><span class="prop-name">{{ __('bank.columns.amount') }}</span><span class="prop-value row-amount @if ($transaction->amount_minor > 0) is-income @endif">{{ $transaction->formattedAmount() }}</span></li>
            @if ($transaction->counterparty)
                <li class="prop-row"><span class="prop-name">{{ __('bank.columns.counterparty') }}</span><span class="prop-value">{{ $transaction->counterparty }}</span></li>
            @endif
            @if ($transaction->counterparty_iban)
                <li class="prop-row"><span class="prop-name">{{ __('bank.iban_of') }}</span><span class="prop-value">{{ trim(chunk_split($transaction->counterparty_iban, 4, ' ')) }}</span></li>
            @endif
            @if ($transaction->purpose)
                <li class="prop-row"><span class="prop-name">{{ __('bank.columns.purpose') }}</span><span class="prop-value">{{ $transaction->purpose }}</span></li>
            @endif
            <li class="prop-row"><span class="prop-name">{{ __('bank.account') }}</span><span class="prop-value">{{ $transaction->account->name }}</span></li>
            @if ($transaction->no_receipt)
                <li class="prop-row"><span class="prop-name">{{ __('bank.no_receipt_reason') }}</span><span class="prop-value">{{ $transaction->no_receipt_reason }}</span></li>
            @endif
        </ul>
        <div class="actions">
            @if ($transaction->no_receipt)
                <form method="post" action="{{ route('bank.no-receipt', $transaction->id) }}" data-bank-action>
                    @csrf
                    <input type="hidden" name="undo" value="1">
                    <button type="submit" class="button-quiet button-small">{{ __('bank.receipt_needed') }}</button>
                </form>
            @else
                <button type="button" class="button-quiet button-small" data-dialog-open="no-receipt-{{ $transaction->id }}">{{ __('bank.no_receipt') }}</button>
            @endif
        </div>
    </section>

    <div class="dialog-backdrop" data-dialog="no-receipt-{{ $transaction->id }}" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="no-receipt-{{ $transaction->id }}-title">
            <h2 id="no-receipt-{{ $transaction->id }}-title">{{ __('bank.no_receipt_title') }}</h2>
            <form method="post" action="{{ route('bank.no-receipt', $transaction->id) }}" data-bank-action>
                @csrf
                <div class="field">
                    <label for="no-receipt-reason-{{ $transaction->id }}">{{ __('bank.no_receipt_reason') }}</label>
                    <input id="no-receipt-reason-{{ $transaction->id }}" name="reason" maxlength="255" required placeholder="{{ __('bank.no_receipt_placeholder') }}">
                </div>
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-dialog-close>{{ __('bank.cancel') }}</button>
                    <button type="submit">{{ __('bank.no_receipt_save') }}</button>
                </div>
            </form>
        </div>
    </div>

    @if ($linked->isNotEmpty() || $hidden > 0)
        <section class="card">
            <h2 class="card-head"><x-icon name="link" />{{ __('bank.linked') }}</h2>
            <ul class="pick-list">
                @foreach ($linked as $row)
                    @php $document = $row['document']; $match = $row['match']; @endphp
                    <li class="pick-row is-static">
                        <span class="row-icon"><x-icon name="file-text" /></span>
                        <span>
                            <strong><a href="{{ route('documents.show', $document->id) }}" data-window>{{ $document->title }}</a></strong>
                            <small>
                                {{ $document->created_at->format('d.m.Y') }}
                                @if ($row['amount'] !== null) · {{ $money(abs($row['amount'])) }}@endif
                                @if ($row['paid']['count'] > 1) · {{ trans_choice('bank.paid_by', $row['paid']['count'], ['count' => $row['paid']['count'], 'sum' => $money($row['paid']['sum'])]) }}@endif
                                @if ($match->isSuggested() && $match->reasons) · {{ $why($match->reasons) }}@endif
                            </small>
                        </span>
                        @if ($match->isSuggested())
                            <span class="pill pill-new">{{ __('bank.status.suggested') }}@if ($match->score) · {{ __('bank.score', ['score' => $match->score]) }}@endif</span>
                            <form method="post" action="{{ route('bank.confirm', $transaction->id) }}" data-bank-action>
                                @csrf
                                <input type="hidden" name="document_id" value="{{ $document->id }}">
                                <button type="submit" class="button-small"><x-icon name="check" />{{ __('bank.confirm') }}</button>
                            </form>
                            <form method="post" action="{{ route('bank.reject', $transaction->id) }}" data-bank-action>
                                @csrf
                                <input type="hidden" name="document_id" value="{{ $document->id }}">
                                <button type="submit" class="control-button control-danger" aria-label="{{ __('bank.reject') }}" data-tooltip="{{ __('bank.reject') }}"><x-icon name="x" /></button>
                            </form>
                        @else
                            <span class="row-actions">
                                <form method="post" action="{{ route('bank.unmatch', $transaction->id) }}" data-bank-action>
                                    @csrf
                                    <input type="hidden" name="document_id" value="{{ $document->id }}">
                                    <button type="submit" class="control-button" aria-label="{{ __('bank.unlink') }}: {{ $document->title }}" data-tooltip="{{ __('bank.unlink') }}"><x-icon name="unlink" /></button>
                                </form>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($hidden > 0)
                <p class="card-note">{{ trans_choice('bank.hidden', $hidden, ['count' => $hidden]) }}</p>
            @endif
            @if ($linked->isNotEmpty())
                {{-- A271: what the documents add up to against the booking; a difference stands out. --}}
                <ul class="prop-list">
                    <li class="prop-row"><span class="prop-name">{{ __('bank.sum_documents') }}</span><span class="prop-value row-amount">{{ $money($sums['documents']) }}</span></li>
                    <li class="prop-row"><span class="prop-name">{{ __('bank.sum_transaction') }}</span><span class="prop-value row-amount">{{ $money($sums['transaction']) }}</span></li>
                    <li class="prop-row"><span class="prop-name">{{ __('bank.sum_difference') }}</span><span class="prop-value">
                        @if ($sums['difference'] === 0)
                            <span class="pill pill-ok">{{ __('bank.balanced') }}</span>
                        @else
                            <span class="pill pill-warning">{{ $money($sums['difference']) }}</span>
                        @endif
                    </span></li>
                </ul>
                @if ($sums['missing'])
                    <p class="card-note">{{ __('bank.sum_missing') }}</p>
                @endif
            @endif
        </section>
    @endif

    <section class="card">
        <h2 class="card-head"><x-icon name="file-text" />{{ __('bank.candidates') }}</h2>
        <p class="card-note">{{ __('bank.candidates_hint') }}</p>
        <ul class="pick-list">
            @forelse ($candidates as $candidate)
                @php $document = $candidate['document']; $amount = $candidateAmounts[$document->id] ?? null; @endphp
                <li class="pick-row is-static">
                    <span class="row-icon"><x-icon name="file-text" /></span>
                    <span>
                        <strong><a href="{{ route('documents.show', $document->id) }}" data-window>{{ $document->title }}</a></strong>
                        <small>
                            {{ $document->created_at->format('d.m.Y') }}
                            @if ($amount !== null) · {{ $money(abs($amount)) }}@endif
                            @if ($candidate['reasons'] !== []) · {{ $why($candidate['reasons']) }}@endif
                        </small>
                    </span>
                    @if ($candidate['score'] >= \App\Domain\Banking\MatchScore::SUGGEST)
                        <span class="pill pill-new">{{ __('bank.score', ['score' => $candidate['score']]) }}</span>
                    @endif
                    <form method="post" action="{{ route('bank.match', $transaction->id) }}" data-bank-action>
                        @csrf
                        <input type="hidden" name="document_id" value="{{ $document->id }}">
                        <button type="submit" class="button-quiet button-small">{{ __('bank.link') }}</button>
                    </form>
                </li>
            @empty
                <li class="list-empty">{{ __('bank.no_candidates') }}</li>
            @endforelse
        </ul>
    </section>
</x-window>
