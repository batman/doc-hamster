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
            <li class="prop-row"><span class="prop-name">{{ __('bank.columns.amount') }}</span><span class="prop-value row-amount @if ($transaction->amount_minor > 0) is-income @endif">{{ $transaction->signedAmount() }}</span></li>
            @if ($transaction->isUnsettled())
                <li class="prop-row"><span class="prop-name">{{ __('bank.columns.state') }}</span><span class="prop-value"><span class="pill pill-warning">{{ __('bank.bank_state.'.$transaction->bank_state) }}</span></span></li>
            @endif
            @if ($transaction->counterparty)
                <li class="prop-row"><span class="prop-name">{{ __('bank.columns.counterparty') }}</span><span class="prop-value">{{ $transaction->counterparty }}</span></li>
            @endif
            @if ($transaction->counterparty_iban)
                <li class="prop-row"><span class="prop-name">{{ __('bank.iban_of') }}</span><span class="prop-value">{{ trim(chunk_split($transaction->counterparty_iban, 4, ' ')) }}</span></li>
            @endif
            @if ($transaction->purpose)
                <li class="prop-row"><span class="prop-name">{{ __('bank.columns.purpose') }}</span><span class="prop-value">{{ $transaction->purpose }}</span></li>
            @endif
            {{-- A289: every further field the bank delivered. --}}
            @foreach ([
                'purpose2' => $transaction->purpose2,
                'reference' => $transaction->reference,
                'kind' => $transaction->kind,
                'value_date' => $transaction->value_date?->format('d.m.Y'),
                'bic' => $transaction->counterparty_bic,
                'creditor_id' => $transaction->creditor_id,
                'orig_amount' => $transaction->orig_amount_minor !== null && $transaction->orig_currency !== null && $transaction->orig_currency !== $transaction->currency ? \App\Domain\Banking\Transaction::signed($transaction->orig_amount_minor, $transaction->orig_currency) : null,
                'fee' => $transaction->fee_minor ? \App\Domain\Banking\Transaction::money(abs($transaction->fee_minor), $transaction->fee_currency ?? $transaction->currency) : null,
                'balance' => $transaction->balance_minor !== null ? $money($transaction->balance_minor) : null,
                'category' => $transaction->category,
                'card' => $transaction->card_last4 ? '•••• '.$transaction->card_last4 : null,
                'state' => $transaction->bank_state && ! $transaction->isUnsettled() ? $transaction->bank_state : null,
                'sub_account' => $transaction->sub_account,
                'mcc' => $transaction->mcc,
                'booking_id' => $transaction->booking_id,
            ] as $field => $value)
                @if ($value !== null && $value !== '')
                    <li class="prop-row"><span class="prop-name">{{ $field === 'balance' ? __('bank.balance_after') : __('bank.columns.'.$field) }}</span><span class="prop-value">{{ $value }}</span></li>
                @endif
            @endforeach
            @if ($parent)
                <li class="prop-row"><span class="prop-name">{{ __('bank.refund_of') }}</span><span class="prop-value"><a href="{{ route('bank.show', $parent->id) }}" data-window>{{ $parent->booking_date->format('d.m.Y') }} · {{ $parent->counterparty ?? $parent->purpose ?? '–' }} · {{ $parent->signedAmount() }}</a></span></li>
            @endif
            <li class="prop-row"><span class="prop-name">{{ __('bank.account') }}</span><span class="prop-value">{{ $transaction->account->name }}</span></li>
            @if ($transaction->no_receipt && ! $parent)
                <li class="prop-row"><span class="prop-name">{{ __('bank.no_receipt_reason') }}</span><span class="prop-value">{{ $transaction->no_receipt_reason }}</span></li>
            @endif
        </ul>
        @if ($parent)
            <p class="card-note">{{ __('bank.refund_hint') }}</p>
        @endif
        @if ($suggestedReason && ! $transaction->no_receipt)
            {{-- A287: the counterparty was marked "kein Beleg nötig" before; one click confirms. --}}
            <div class="suggest-chips" role="group" aria-label="{{ __('bank.suggested_hint') }}">
                <span class="suggest-chips-label">{{ __('bank.suggested_reason', ['reason' => $suggestedReason->name]) }}</span>
                <form method="post" action="{{ route('bank.no-receipt', $transaction->id) }}" data-bank-action>
                    @csrf
                    <input type="hidden" name="reason_id" value="{{ $suggestedReason->id }}">
                    <button type="submit" class="suggest-chip" data-tooltip="{{ __('bank.suggested_hint') }}">{{ __('bank.accept') }}</button>
                </form>
                <form method="post" action="{{ route('bank.no-receipt', $transaction->id) }}" data-bank-action>
                    @csrf
                    <input type="hidden" name="undo" value="1">
                    <button type="submit" class="button-quiet button-small">{{ __('bank.dismiss') }}</button>
                </form>
            </div>
        @endif
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

    @if ($transaction->extra)
        {{-- A290: the columns nobody mapped, as the file had them. --}}
        <x-fold-card fold="bank.details" :title="__('bank.details')" icon="list-details" folded>
            <ul class="prop-list">
                @foreach ($transaction->extra as $name => $value)
                    <li class="prop-row"><span class="prop-name">{{ $name }}</span><span class="prop-value">{{ $value }}</span></li>
                @endforeach
            </ul>
        </x-fold-card>
    @endif

    @if ($children->isNotEmpty())
        <section class="card">
            <h2 class="card-head"><x-icon name="arrow-back-up" />{{ __('bank.refunds') }}</h2>
            <ul class="pick-list">
                @foreach ($children as $child)
                    <li class="pick-row is-static">
                        <span class="row-icon"><x-icon :name="$child->natureIcon()" /></span>
                        <span>
                            <strong><a href="{{ route('bank.show', $child->id) }}" data-window>{{ $child->counterparty ?? $child->purpose ?? '–' }}</a></strong>
                            <small>{{ $child->booking_date->format('d.m.Y') }}@if ($child->kind) · {{ $child->kind }}@endif</small>
                        </span>
                        <span class="row-amount @if ($child->amount_minor > 0) is-income @endif">{{ $child->signedAmount() }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- A286, A288: a click on a reason saves at once; only "Anderer Grund" opens a field (Enter or ✓). --}}
    <div class="dialog-backdrop" data-dialog="no-receipt-{{ $transaction->id }}" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="no-receipt-{{ $transaction->id }}-title">
            <h2 id="no-receipt-{{ $transaction->id }}-title">{{ __('bank.no_receipt_pick') }}</h2>
            <div class="menu-stack">
                @foreach ($reasons as $reason)
                    <form method="post" action="{{ route('bank.no-receipt', $transaction->id) }}" data-bank-action>
                        @csrf
                        <input type="hidden" name="reason_id" value="{{ $reason->id }}">
                        <button type="submit" class="menu-item"><x-icon name="receipt-off" /><span>{{ $reason->name }}</span></button>
                    </form>
                @endforeach
                <button type="button" class="menu-item" data-reason-other aria-expanded="false"><x-icon name="pencil" /><span>{{ __('bank.reason_other') }}</span></button>
                <form method="post" action="{{ route('bank.no-receipt', $transaction->id) }}" data-bank-action data-reason-other-form hidden>
                    @csrf
                    <div class="field-addon">
                        <input name="reason" maxlength="255" required placeholder="{{ __('bank.reason_other_placeholder') }}" aria-label="{{ __('bank.reason_other') }}">
                        <span class="addon-end"><button type="submit" class="control-button addon-button" aria-label="{{ __('bank.no_receipt_save') }}" data-tooltip="{{ __('bank.no_receipt_save') }}"><x-icon name="check" /></button></span>
                    </div>
                </form>
            </div>
            <div class="dialog-actions">
                <button type="button" class="button-quiet" data-dialog-close>{{ __('bank.cancel') }}</button>
            </div>
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
                            {{-- A300: the card's category suggests a tag for the document. --}}
                            @if ($mccTag && ! $match->isSuggested() && ! $document->tags->contains(static fn ($t): bool => mb_strtolower($t->name) === mb_strtolower($mccTag)))
                                <form method="post" action="{{ route('bank.tag', $transaction->id) }}" data-bank-action class="suggest-chips">
                                    @csrf
                                    <input type="hidden" name="document_id" value="{{ $document->id }}">
                                    <input type="hidden" name="tag" value="{{ $mccTag }}">
                                    <button type="submit" class="suggest-chip" aria-label="{{ __('bank.tag_suggest', ['mcc' => $transaction->mcc]) }}" data-tooltip="{{ __('bank.tag_suggest', ['mcc' => $transaction->mcc]) }}">#{{ $mccTag }}</button>
                                </form>
                            @endif
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
