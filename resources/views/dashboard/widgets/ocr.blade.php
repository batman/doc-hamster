{{-- Texterkennung (A349): the short state of OcrProgress. --}}
<p class="dash-summary">{{ $open > 0 ? trans_choice('dashboard.ocr_open', $open, ['count' => $open]) : __('dashboard.ocr_idle') }}</p>
@if ($total > 0)
    <progress class="dash-progress" max="{{ $total }}" value="{{ $done }}" aria-label="{{ __('dashboard.ocr_done', ['done' => $done, 'total' => $total]) }}"></progress>
    <p class="card-note">{{ __('dashboard.ocr_done', ['done' => $done, 'total' => $total]) }}</p>
@endif
@can('settings.manage')
    <p class="dash-more"><a href="{{ route('ocr.live') }}" data-window>{{ __('ocr.title') }}</a></p>
@endcan
