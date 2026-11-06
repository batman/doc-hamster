{{-- Name, place and bank matching of a folder. $locked: the folder "Belege" (no parent choice). $self hides the folder itself and what lies below it from the parent list. --}}
@php
    $all = \App\Domain\Documents\Folder::flat();
    $blocked = $self !== null ? \App\Domain\Documents\Folder::branchIds($self) : [];
@endphp
<label for="name">{{ __('folders.name') }}</label>
<input id="name" name="name" value="{{ old('name', $name) }}" maxlength="100" required>
@if ($locked)
    <p class="muted">{{ __('folders.default_note') }}</p>
@else
<label for="parent_id">{{ __('folders.parent') }}</label>
<select id="parent_id" name="parent_id">
    <option value="">{{ __('folders.top') }}</option>
    @foreach ($all as $row)
        @unless (in_array($row['folder']->id, $blocked, true))
            <option value="{{ $row['folder']->id }}" @selected(old('parent_id', $parent) === $row['folder']->id)>{{ str_repeat('– ', $row['depth']) }}{{ $row['folder']->name }}</option>
        @endunless
    @endforeach
</select>
@endif
<label class="check"><input type="checkbox" name="bank_matching" value="1" @checked(old('bank_matching', $matching))> {{ __('folders.bank_matching') }}</label>
<p class="muted">{{ __('folders.bank_matching_hint') }}</p>
