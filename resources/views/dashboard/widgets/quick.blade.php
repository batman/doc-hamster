{{-- Schnellzugriff (A349, A351): tiles of pinned places – app windows (open on the right) or https links (new tab).
     In edit mode each tile has an X, and "+ Link" pins an https address. --}}
@if ($pins === [])
    <x-empty icon="pin" :text="__('dashboard.quick_hint')" />
@else
    <ul class="dash-tiles">
        @foreach ($pins as $pin)
            @php $external = \App\Domain\Dashboard\DashboardLayout::isExternal($pin['url']); @endphp
            <li class="dash-tile-item">
                <a class="dash-tile" href="{{ $pin['url'] }}" @if ($external) target="_blank" rel="noopener noreferrer" @else data-window @endif title="{{ $pin['title'] }}">
                    <x-icon :name="$pin['icon']" /><span>{{ $pin['title'] }}</span>
                </a>
                <button type="button" class="control-button dash-tile-x dash-edit-only" data-dash-unpin="{{ $pin['url'] }}" aria-label="{{ __('dashboard.unpin') }}" data-tooltip="{{ __('dashboard.unpin') }}"><x-icon name="x" /></button>
            </li>
        @endforeach
    </ul>
@endif
<form class="dash-edit-only dash-link-form" method="post" action="{{ route('dashboard.pins.store') }}" data-dash-link>
    @csrf
    <div class="field">
        <label>{{ __('dashboard.link_url') }}<input type="url" name="url" placeholder="https://" required maxlength="{{ \App\Domain\Dashboard\DashboardLayout::URL_MAX }}" autocomplete="off"></label>
    </div>
    <div class="field">
        <label>{{ __('dashboard.link_title') }}<input name="title" maxlength="{{ \App\Domain\Dashboard\DashboardLayout::TITLE_MAX }}" autocomplete="off"></label>
    </div>
    <input type="hidden" name="icon" value="external-link">
    <button type="submit" class="button-quiet"><x-icon name="plus" />{{ __('dashboard.link_save') }}</button>
</form>
