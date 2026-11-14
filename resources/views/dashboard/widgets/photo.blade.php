{{-- Foto (A349): the person's own picture (private, served only to them). A picture dropped here (or chosen in edit mode) replaces it. --}}
<div class="dash-photo" data-dash-photo-drop>
    @if ($photo)
        <img src="{{ $photo }}" alt="{{ __('dashboard.photo_alt') }}">
    @else
        <p class="dash-empty"><x-icon name="photo" />{{ __('dashboard.photo_hint') }}</p>
    @endif
</div>
<div class="dash-edit-only dash-photo-tools">
    <label class="icon-button button-small" data-tooltip="{{ __('dashboard.photo_pick') }}">
        <input class="visually-hidden" type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-dash-photo-input aria-label="{{ __('dashboard.photo_pick') }}">
        <x-icon name="upload" />
    </label>
    @if ($photo)
        <button type="button" class="icon-button button-small" data-dash-photo-remove aria-label="{{ __('dashboard.photo_remove') }}" data-tooltip="{{ __('dashboard.photo_remove') }}"><x-icon name="trash" /></button>
    @endif
</div>
