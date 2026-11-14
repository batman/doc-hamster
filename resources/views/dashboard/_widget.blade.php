{{-- One Home widget (A349, A350): a card; in edit mode its tool line (drag handle itself: name, size S/M/L, X) shows on top.
     $widget = ['type', 'size', 'data']. The body is dashboard/widgets/<type>. --}}
@php
    $type = $widget['type'];
    $meta = \App\Domain\Dashboard\DashboardLayout::WIDGETS[$type];
    $name = __('dashboard.widgets.'.$type);
@endphp
<li class="card dash-widget dash-{{ $type }} is-{{ $widget['size'] }}" data-widget="{{ $type }}" data-size="{{ $widget['size'] }}" aria-label="{{ $name }}">
    {{-- Point 192: no grip – the whole tool line drags (and takes the arrow keys when focused). --}}
    <div class="dash-tools dash-grip" data-dash-grip tabindex="0" aria-label="{{ __('dashboard.move') }}">
        <span class="dash-tools-name">{{ $name }}</span>
        <span class="switcher" data-switch-static role="group" aria-label="{{ __('dashboard.size') }}">
            @foreach (\App\Domain\Dashboard\DashboardLayout::SIZES as $size)
                <button type="button" data-dash-size="{{ $size }}" aria-pressed="{{ $size === $widget['size'] ? 'true' : 'false' }}" aria-label="{{ __('dashboard.sizes.'.$size) }}" data-tooltip="{{ __('dashboard.sizes.'.$size) }}">{{ strtoupper($size) }}</button>
            @endforeach
        </span>
        <button type="button" class="icon-button button-small" data-dash-remove aria-label="{{ __('dashboard.remove') }}" data-tooltip="{{ __('dashboard.remove') }}"><x-icon name="x" /></button>
    </div>
    @unless (in_array($type, ['photo', 'greeting', 'drop'], true))
        <h2 class="card-head"><x-icon :name="$meta['icon']" />{{ $name }}</h2>
    @endunless
    @include('dashboard.widgets.'.$type, $widget['data'])
</li>
