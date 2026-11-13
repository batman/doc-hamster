{{-- Live card (point 124, A255-A259): the document being read with the recognised words over it (orange boxes, page by
     page), its details, figures and the last ten finished documents. modules/ocr-live.js fills it: live from the browser
     OCR of this tab (DocHamster events), otherwise from the status JSON (?live=1) every few seconds. Idle: "Wartet auf
     Dokumente" and the last finished document. --}}
@php
    $figures = $live['figures'];
    $recent = $live['recent'];
    $seconds = static function (?int $s): string {
        if ($s === null) {
            return __('ocr.live.none');
        }

        return match (true) {
            $s < 60 => __('ocr.live.seconds', ['s' => $s]),
            $s < 3600 => __('ocr.live.minutes', ['m' => (int) ceil($s / 60)]),
            default => __('ocr.live.hours', ['h' => intdiv($s, 3600), 'm' => intdiv($s % 3600, 60)]),
        };
    };
@endphp
<x-fold-card fold="ocr.live" :title="__('ocr.live.title')" icon="eye" class="ocr-live"
    data-ocr-live="{{ json_encode($live) }}"
    data-labels="{{ json_encode(__('ocr.live')) }}">
    <div class="ocr-live-now">
        <div class="ocr-live-stage" data-ocr-live-stage>
            <div class="ocr-live-page" data-ocr-live-page hidden></div>
            <p class="list-empty" data-ocr-live-idle>{{ __('ocr.live.idle') }}</p>
        </div>
        <dl class="detail-facts ocr-live-facts" data-ocr-live-facts>
            <dt>{{ __('ocr.live.document') }}</dt><dd data-live="title">{{ __('ocr.live.none') }}</dd>
            <dt>{{ __('ocr.live.page') }}</dt><dd data-live="page">{{ __('ocr.live.none') }}</dd>
            <dt>{{ __('ocr.live.engine') }}</dt><dd data-live="engine">{{ __('ocr.live.none') }}</dd>
            <dt>{{ __('ocr.live.percent') }}</dt><dd data-live="percent">{{ __('ocr.live.none') }}</dd>
            <dt>{{ __('ocr.live.elapsed') }}</dt><dd data-live="elapsed">{{ __('ocr.live.none') }}</dd>
            <dt data-live-step hidden>{{ __('ocr.live.status') }}</dt><dd data-live="step" data-live-step hidden></dd>
        </dl>
    </div>

    <ul class="ocr-counts ocr-live-figures">
        <li><strong data-live-figure="per_hour">{{ $figures['per_hour'] }}</strong><span>{{ __('ocr.live.per_hour') }}</span></li>
        <li><strong data-live-figure="remaining_seconds">{{ $seconds($figures['remaining_seconds']) }}</strong><span>{{ __('ocr.live.remaining') }}</span></li>
        <li><strong data-live-figure="seconds_per_page">{{ $figures['seconds_per_page'] !== null ? number_format($figures['seconds_per_page'], 1, ',', '.') : __('ocr.live.none') }}</strong><span>{{ __('ocr.live.per_page') }}</span></li>
    </ul>

    <h3 class="ocr-live-subhead">{{ __('ocr.live.recent') }}</h3>
    {{-- Symbols for rows the script draws later. --}}
    <span hidden data-live-icon="ok"><x-icon name="check" /></span><span hidden data-live-icon="failed"><x-icon name="x" /></span><span hidden data-live-icon="file"><x-icon name="file-text" /></span>
    <ul class="ocr-live-recent" data-ocr-live-recent>
        @forelse ($recent as $row)
            <li>
                <a class="ocr-live-doc" href="{{ $row['url'] }}" data-window title="{{ $row['title'] }}">
                    <span class="thumb-frame">
                        @if ($row['thumb'])<img class="thumb" src="{{ $row['thumb'] }}" alt="" loading="lazy" draggable="false">@else<x-icon name="file-text" />@endif
                    </span>
                    <span @class(['ocr-live-result', 'is-ok' => $row['ok'], 'is-failed' => ! $row['ok']]) role="img" aria-label="{{ __($row['ok'] ? 'ocr.live.ok' : 'ocr.live.failed') }}"><x-icon :name="$row['ok'] ? 'check' : 'x'" /></span>
                    <span class="ocr-live-doc-text">{{ $row['value'] ?? $row['title'] }}</span>
                </a>
            </li>
        @empty
            <li class="list-empty">{{ __('ocr.live.recent_empty') }}</li>
        @endforelse
    </ul>
</x-fold-card>
