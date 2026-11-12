{{-- Name, place, colour, symbol and bank matching of a folder. $locked: the folder "Dokumente allgemein" (name and place fixed). $self hides the folder itself
     and what lies below it from the parent list. Colour: none, the six colours of the design, or an own one; symbol from Folder::ICONS (PO, 2026-11-07). --}}
@php
    $all = \App\Domain\Documents\Folder::flat();
    $blocked = $self !== null ? \App\Domain\Documents\Folder::branchIds($self) : [];
    $color = old('color_hex') ?: old('color', $color ?? 'none');
    $icon = old('icon', $icon);
    $palette = \App\Domain\Documents\Tag::PALETTE;
    // Ids carry the folder's id (or "new"): two folder windows can be open at once (window system).
    $key = $self ?? 'new';
@endphp
<input type="hidden" name="look" value="1">
<div class="field">
    <label for="name-{{ $key }}">{{ __('folders.name') }}</label>
    @if ($self !== null)
        {{-- Properties save on change (A191-A194): the ✓ inside the field shows once the name differs; Enter or ✓ saves. --}}
        <div class="field-addon">
            <input id="name-{{ $key }}" name="name" value="{{ old('name', $name) }}" maxlength="100" required>
            <span class="addon-end"><button type="button" class="control-button addon-button" data-field-save aria-label="{{ __('folders.save') }}" data-tooltip="{{ __('folders.save') }}" hidden><x-icon name="check" /></button></span>
        </div>
    @else
        <input id="name-{{ $key }}" name="name" value="{{ old('name', $name) }}" maxlength="100" required @if ($name === '') autofocus @endif>
    @endif
</div>
@if ($locked)
    <p class="muted">{{ __('folders.default_note') }}</p>
@else
<label for="parent_id-{{ $key }}">{{ __('folders.parent') }}</label>
<select id="parent_id-{{ $key }}" name="parent_id">
    <option value="">{{ __('folders.top') }}</option>
    @foreach ($all as $row)
        @unless (in_array($row['folder']->id, $blocked, true) || $row['depth'] >= \App\Domain\Documents\Folder::MAX_DEPTH)
            <option value="{{ $row['folder']->id }}" @selected(old('parent_id', $parent) === $row['folder']->id)>{{ str_repeat('– ', $row['depth']) }}{{ $row['folder']->name }}</option>
        @endunless
    @endforeach
</select>
@endif

<x-color-picker name="color" :value="$color" :allow-none="true" :allow-own="true" :key="$key" :legend="__('folders.color')" />

<fieldset class="tag-color-field">
    <legend>{{ __('folders.icon') }}</legend>
    <div class="folder-icons">
        @foreach (\App\Domain\Documents\Folder::ICONS as $choice)
            <label class="folder-icon-choice" title="{{ __('folders.icons.'.$choice) }}"><input type="radio" name="icon" value="{{ $choice }}" @checked(($icon ?? ($locked ? 'file-text' : 'folder')) === $choice) aria-label="{{ __('folders.icons.'.$choice) }}"><span><x-icon :name="$choice" /></span></label>
        @endforeach
    </div>
</fieldset>

<label class="check"><input type="checkbox" name="bank_matching" value="1" @checked(old('bank_matching', $matching))> {{ __('folders.bank_matching') }}</label>
<p class="muted">{{ __('folders.bank_matching_hint') }}</p>
