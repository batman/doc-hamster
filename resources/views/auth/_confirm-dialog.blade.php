{{-- Bestätigung (Sudo), point 212: once per page, outside the windows; core/confirm.js opens it when a sensitive action
     needs a fresh confirmation, then the action goes on. `data-confirm-open`: a plain form post came back unconfirmed. --}}
<div class="dialog-backdrop" data-dialog="confirm-sudo" data-confirm-sudo-dialog data-status="{{ route('confirm.status') }}" @if (session('confirm_required')) data-confirm-open data-again="{{ __('confirm.again') }}" @endif hidden>
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="confirm-sudo-title">
        <h2 id="confirm-sudo-title">{{ __('confirm.title') }}</h2>
        <p class="card-text">{{ __('confirm.text') }}</p>
        <form method="post" action="{{ route('confirm.store') }}" data-failed="{{ __('confirm.failed') }}">
            @csrf
            <div class="field">
                <label for="confirm-sudo-password">{{ __('confirm.password') }}</label>
                <input id="confirm-sudo-password" type="password" name="password" autocomplete="current-password" required>
                <p class="field-hint" data-confirm-error role="alert" hidden></p>
            </div>
            <div class="dialog-actions">
                <button type="submit"><x-icon name="lock" />{{ __('confirm.ok') }}</button>
            </div>
        </form>
    </div>
</div>
