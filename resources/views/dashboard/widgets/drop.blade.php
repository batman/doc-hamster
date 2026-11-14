{{-- Ablegen (A349): a big drop field; files go to the same upload as the main menu's drop (into the Posteingang). --}}
<div class="dash-drop" data-dash-drop>
    <x-icon name="upload" />
    <strong>{{ __('dashboard.drop') }}</strong>
    <small>{{ __('dashboard.drop_hint') }}</small>
    <label class="button button-quiet button-small">
        <input class="visually-hidden" type="file" multiple accept="image/*,application/pdf" data-dash-drop-input aria-label="{{ __('dashboard.drop_pick') }}">
        <x-icon name="upload" />{{ __('dashboard.drop_pick') }}
    </label>
</div>
