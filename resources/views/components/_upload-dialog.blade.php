{{-- Hochlade-Dialog (points 27–29, A77–A81; core/upload.js, core/upload.css): one per page, used by every way of
     uploading (Posteingang button, files dropped onto folders, windows, the menu or Home). Bar by data volume, "1,8 MB von
     4,2 MB · 850 KB/s · noch etwa 3 Sekunden", with several files "2 von 3" and one row per file; "Im Hintergrund weiter"
     shrinks it to the chip at the bottom right. At the end the protocol (uploaded, not uploaded with reason, already
     there) as a Kopiertext and "Erledigt", saved in the Logbuch from two files on or with a problem (A83, A84); one file without a problem closes by itself. --}}
<div class="dialog-backdrop" data-upload-dialog hidden
     data-title-one="{{ __('upload.title_one', ['name' => ':name']) }}" data-title-many="{{ __('upload.title_many', ['count' => ':count']) }}"
     data-title-done="{{ __('upload.title_done') }}" data-title-problems="{{ __('upload.title_problems') }}"
     data-stats="{{ __('upload.stats', ['done' => ':done', 'total' => ':total']) }}" data-speed="{{ __('upload.speed', ['speed' => ':speed']) }}"
     data-left-seconds="{{ __('upload.left_seconds', ['count' => ':count']) }}" data-left-minutes="{{ __('upload.left_minutes', ['count' => ':count']) }}"
     data-count="{{ __('upload.count', ['done' => ':done', 'total' => ':total']) }}" data-target="{{ __('upload.target', ['name' => ':name']) }}"
     data-summary="{{ __('upload.summary', ['done' => ':done', 'failed' => ':failed', 'known' => ':known']) }}"
     data-state-waiting="{{ __('upload.state.waiting') }}" data-state-sending="{{ __('upload.state.sending') }}" data-state-done="{{ __('upload.state.done') }}"
     data-state-failed="{{ __('upload.state.failed') }}" data-state-known="{{ __('upload.state.known') }}"
     data-error-network="{{ __('upload.error.network') }}" data-error-size="{{ __('upload.error.size') }}" data-error-other="{{ __('upload.error.other') }}"
     data-log-head="{{ __('upload.log.head', ['name' => ':name', 'date' => ':date']) }}" data-log-done="{{ __('upload.log.done') }}"
     data-log-failed="{{ __('upload.log.failed') }}" data-log-known="{{ __('upload.log.known') }}"
     data-leave="{{ __('upload.leave') }}" data-protocol-url="{{ route('documents.upload-protocol') }}">
    <div class="dialog upload-dialog" role="dialog" aria-modal="true" aria-labelledby="upload-dialog-title">
        <h2 id="upload-dialog-title" data-upload-title></h2>
        <p class="field-hint" data-upload-target></p>
        <progress class="upload-bar" max="1000" value="0" data-upload-bar></progress>
        <p class="upload-stats" role="status" aria-live="polite"><span data-upload-stats></span><span data-upload-count></span></p>
        <ul class="upload-files" data-upload-files></ul>
        {{-- A83: at the end the protocol as text to read, mark or copy (never copied by itself); A84: also in the Logbuch. --}}
        <div class="upload-protocol" data-upload-protocol hidden>
            <x-copy-text id="upload-protocol-text" :label="__('upload.protocol')" text="" rows="8" />
            <p class="field-hint" data-upload-logged hidden><a href="{{ route('log.index') }}">{{ __('upload.in_log') }}</a></p>
        </div>
        <div class="dialog-actions">
            <button type="button" class="button-quiet" data-upload-background><x-icon name="arrows-minimize" />{{ __('upload.background') }}</button>
            <button type="button" class="button-quiet" data-upload-retry hidden><x-icon name="refresh" />{{ __('upload.retry') }}</button>
            <button type="button" data-upload-done hidden><x-icon name="check" />{{ __('upload.done') }}</button>
        </div>
    </div>
</div>
<button type="button" class="upload-chip" data-upload-chip hidden aria-label="{{ __('upload.show') }}" data-tooltip="{{ __('upload.show') }}">
    <x-icon name="upload" /><span data-upload-chip-text></span><progress max="1000" value="0" data-upload-chip-bar></progress>
</button>
