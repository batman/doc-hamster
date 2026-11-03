{{-- The booking beside the list; below 80rem the CSS shows it as a dialog. --}}
<a class="split-backdrop" href="{{ route('bank.index', request()->query()) }}" aria-label="{{ __('documents.close') }}"></a>
<aside class="split-detail" aria-label="{{ $transaction->formattedAmount() }}">
    <div class="detail-head">
        <h2>{{ $transaction->formattedAmount() }}</h2>
        <a class="icon-button" href="{{ route('bank.index', request()->query()) }}" aria-label="{{ __('documents.close') }}" data-tooltip="{{ __('documents.close') }}"><x-icon name="x" /></a>
    </div>
    <p>{{ $transaction->booking_date->format('d.m.Y') }} · {{ $transaction->counterparty }}</p>
    <p class="muted">{{ $transaction->account->name }}</p>
    @if ($transaction->purpose)
        <p class="muted">{{ $transaction->purpose }}</p>
    @endif

    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    @if ($linked->isNotEmpty() || $hidden > 0)
        <h2>{{ __('bank.linked') }}</h2>
        <ul class="documents">
            @foreach ($linked as $document)
                @component('bank._receipt', ['document' => $document])
                    <form method="post" action="{{ route('bank.unmatch', $transaction->id) }}">
                        @csrf
                        <input type="hidden" name="document_id" value="{{ $document->id }}">
                        <button type="submit">{{ __('bank.unlink') }}</button>
                    </form>
                @endcomponent
            @endforeach
        </ul>
        @if ($hidden > 0)
            <p class="muted">{{ trans_choice('bank.hidden', $hidden, ['count' => $hidden]) }}</p>
        @endif
    @endif

    <h2>{{ __('bank.candidates') }}</h2>
    @if ($candidates->isEmpty())
        <p class="muted">{{ __('bank.no_candidates') }}</p>
    @else
        <p class="muted">{{ __('bank.candidates_hint') }}</p>
        <ul class="documents">
            @foreach ($candidates as $document)
                @component('bank._receipt', ['document' => $document])
                    <form method="post" action="{{ route('bank.match', $transaction->id) }}">
                        @csrf
                        <input type="hidden" name="document_id" value="{{ $document->id }}">
                        <button type="submit">{{ __('bank.link') }}</button>
                    </form>
                @endcomponent
            @endforeach
        </ul>
    @endif
</aside>
