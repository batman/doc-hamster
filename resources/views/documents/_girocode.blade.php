{{-- GiroCode card of a document (points 120, 120.4, A237-A239, A247, A249, A250; GiroCodeCard): payee, IBAN, BIC, amount and
     Verwendungszweck from the properties, each editable in place (saved as confirmed property; an emptied Verwendungszweck is
     made again from invoice and customer number). The QR code (EPC, SVG from documents.girocode.svg) follows the fields live
     while typing (girocode.js). Unsure amount: other amounts as chips; an IBAN with an OCR swap: the repaired one as chip.
     No code without payee, IBAN and an amount in euros – the card says what is missing. "Als bezahlt markieren" sets
     paid_at; a paid document shows "Bezahlt am …" with Rückgängig instead of the code. Shown once there is an IBAN (or the
     document is paid). No ids inside: the card reloads itself on properties:changed in every window with this document. --}}
@php
    $giro = app(\App\Domain\Payments\GiroCodeCard::class)->build($document);
    $giroEdit = Gate::allows('documents.edit');
    $giroSet = route('documents.properties.set', $document->id);
    $giroParam = ['sender' => 'name', 'iban' => 'iban', 'bic' => 'bic', 'invoice_amount' => 'amount', 'payment_reference' => 'ref'];
    $giroMax = ['sender' => 70, 'iban' => 42, 'bic' => 11, 'invoice_amount' => 30, 'payment_reference' => 140];
@endphp
<section class="detail-section doc-properties girocode" data-girocode="{{ route('documents.girocode', $document->id) }}" data-document="{{ $document->id }}"
    data-svg="{{ route('documents.girocode.svg', $document->id) }}" data-set="{{ $giroSet }}" data-failed="{{ __('girocode.failed') }}" @unless ($giro['show']) hidden @endunless>
    <div class="doc-properties-head">
        <h3><x-icon name="qrcode" />{{ __('girocode.title') }}</h3>
    </div>
    <ul class="prop-list">
        @foreach ($giro['rows'] as $row)
            <li class="prop-row @if ($row['suggested']) is-suggested @endif" data-giro-param="{{ $giroParam[$row['key']] }}"
                @if ($row['page'] !== null && $row['box'] !== null) data-page="{{ $row['page'] }}" data-box="{{ json_encode($row['box']) }}" @endif>
                <span class="prop-name">{{ $row['label'] }}</span>
                <span class="prop-value">
                    @if ($giroEdit)
                        <x-edit-in-place :name="$row['key']" :value="$row['text']" :action="$giroSet" :label="$row['label']" :placeholder="$row['key'] === 'payment_reference' ? __('girocode.reference_hint') : __('girocode.empty')" :maxlength="$giroMax[$row['key']]" />
                    @else
                        <span>{{ $row['text'] !== '' ? $row['text'] : '–' }}</span>
                    @endif
                    @if ($row['unsure'])
                        <span class="pill pill-warning">{{ __('girocode.unsure') }}</span>
                    @elseif ($row['suggested'])
                        <span class="pill pill-new">{{ __('girocode.suggested') }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>

    @if ($giroEdit && $giro['chips'] !== [] && $giro['paid'] === null)
        <div class="suggest-chips" role="group" aria-label="{{ __('girocode.other_amounts') }}">
            <span class="suggest-chips-label">{{ __('girocode.other_amounts') }}</span>
            @foreach ($giro['chips'] as $chip)
                <button type="button" class="suggest-chip" data-giro-take="invoice_amount" data-value="{{ $chip['text'] }}"
                    @if ($chip['page'] !== null && $chip['box'] !== null) data-page="{{ $chip['page'] }}" data-box="{{ json_encode($chip['box']) }}" @endif
                    aria-label="{{ __('girocode.take_amount', ['value' => $chip['text']]) }}" data-tooltip="{{ __('girocode.take_amount', ['value' => $chip['text']]) }}">{{ $chip['text'] }}</button>
            @endforeach
        </div>
    @endif

    @if ($giroEdit && $giro['swap'] !== null)
        <div class="girocode-swap">
            <p class="card-note">{{ __('girocode.swap', ['original' => $giro['swap']['original']]) }}</p>
            <div class="suggest-chips">
                <button type="button" class="suggest-chip" data-giro-take="iban" data-value="{{ $giro['swap']['text'] }}"
                    @if ($giro['swap']['page'] !== null && $giro['swap']['box'] !== null) data-page="{{ $giro['swap']['page'] }}" data-box="{{ json_encode($giro['swap']['box']) }}" @endif
                    aria-label="{{ __('girocode.take_iban', ['value' => $giro['swap']['text']]) }}" data-tooltip="{{ __('girocode.take_iban', ['value' => $giro['swap']['text']]) }}">{{ $giro['swap']['text'] }}</button>
            </div>
        </div>
    @endif

    @if ($giro['paid'] !== null)
        <div class="girocode-paid" role="status">
            <x-icon name="circle-check" />
            <strong>{{ __('girocode.paid', ['date' => $giro['paid']]) }}</strong>
            @if ($giroEdit && $giro['canPay'])
                <form method="post" action="{{ $giroSet }}" data-giro-action>
                    @csrf
                    <input type="hidden" name="property" value="paid_at">
                    <input type="hidden" name="value" value="">
                    <button type="submit" class="button-quiet button-small"><x-icon name="arrow-back-up" />{{ __('girocode.undo') }}</button>
                </form>
            @endif
        </div>
    @else
        @if ($giro['skonto'] !== null && $giro['code'])
            <div class="girocode-skonto">
                <div class="button-group" role="group" aria-label="{{ __('girocode.skonto') }}">
                    <button type="button" class="button-quiet button-small" aria-pressed="true" data-giro-skonto="0">{{ __('girocode.skonto_full') }}</button>
                    <button type="button" class="button-quiet button-small" aria-pressed="false" data-giro-skonto="1">{{ __('girocode.skonto_with', ['percent' => $giro['skonto']['percent']]) }}</button>
                </div>
                <p class="card-note" data-giro-pay data-full="{{ __('girocode.pay', ['amount' => $giro['skonto']['full']]) }}"
                    data-reduced="{{ __('girocode.pay', ['amount' => $giro['skonto']['reduced']]) }}{{ $giro['skonto']['until'] !== null ? ' · '.__('girocode.skonto_until', ['date' => $giro['skonto']['until']]) : '' }}">{{ __('girocode.pay', ['amount' => $giro['skonto']['full']]) }}</p>
            </div>
        @endif

        <figure class="qr-card" data-giro-qr @unless ($giro['code']) hidden @endunless>
            <img @if ($giro['code']) src="{{ route('documents.girocode.svg', $document->id) }}" @endif alt="{{ __('girocode.alt') }}" width="240" height="240" data-giro-img>
            <figcaption>{{ __('girocode.scan') }}</figcaption>
        </figure>
        <div class="qr-card is-missing" data-giro-missing @if ($giro['code']) hidden @endif>
            <x-icon name="qrcode" />
            <p>{{ $giro['euro'] ? __('girocode.missing', ['fields' => implode(', ', $giro['missing'])]) : __('girocode.not_euro', ['currency' => $giro['currency']]) }}</p>
        </div>

        @if ($giroEdit && $giro['canPay'])
            <form method="post" action="{{ $giroSet }}" class="girocode-pay" data-giro-action>
                @csrf
                <input type="hidden" name="property" value="paid_at">
                <input type="hidden" name="value" value="{{ now()->format('d.m.Y') }}">
                <button type="submit"><x-icon name="check" />{{ __('girocode.mark_paid') }}</button>
            </form>
        @endif
    @endif
</section>
