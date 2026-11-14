{{-- Point 214, A39: the Belegdatum under the title of the detail window. A click opens "Markieren als" with the
     Rechnungsdatum chosen (same editing as the property, with the "lesen als Tag, Monat, Jahr" link); saving confirms
     it. Without a date of its own: "Belegdatum fehlt" (muted). A381: a recognised date that was not taken shows a hint.
     No ids inside: it reloads with the properties card on properties:changed (properties.js, JSON `date_html`). --}}
@php
    $receiptDates = app(\App\Domain\Documents\ReceiptDate::class);
    $dateMissing = \App\Domain\Documents\ReceiptDate::missing($document);
    $dateDoubt = $receiptDates->doubtful($document);
    $dateText = $dateMissing ? __('receipt_date.missing') : __('receipt_date.label').' '.$document->dateBy('doc')->format('d.m.Y');
    $dateEdit = Gate::allows('documents.edit') && \App\Domain\Properties\Property::forDocument($document)->contains('key', 'invoice_date');
    $dateSource = $document->doc_date_source;
@endphp
<div class="doc-date" data-doc-date="{{ route('documents.properties', $document->id) }}" data-document="{{ $document->id }}">
    @if ($dateEdit)
        <button type="button" @class(['button-link', 'is-quiet' => $dateMissing]) data-mark-as="{{ $document->id }}" data-mark-property="invoice_date"
            data-mark-text="{{ $dateMissing ? '' : $document->dateBy('doc')->format('d.m.Y') }}"
            data-tooltip="{{ $dateMissing ? __('receipt_date.set') : __('receipt_date.edit') }}"><x-icon name="calendar" />{{ $dateText }}</button>
    @else
        <span @class(['doc-date-value', 'muted' => $dateMissing])><x-icon name="calendar" />{{ $dateText }}</span>
    @endif
    @if (! $dateMissing && in_array($dateSource, ['recognised', 'exif', 'pdf'], true))
        <span class="pill">{{ __('receipt_date.sources.'.$dateSource) }}</span>
    @endif
    @if ($dateDoubt)
        <p class="field-hint doc-date-hint"><x-icon name="alert-triangle" />{{ __('receipt_date.doubtful', ['date' => $dateDoubt->format('d.m.Y')]) }}</p>
    @endif
</div>
