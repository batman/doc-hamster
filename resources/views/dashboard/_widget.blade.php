{{-- One Home widget (A349, A350): a card; in edit mode its tool line (grip, name, size S/M/L, X) shows on top.
     $widget = ['type', 'size', 'data']. The body is dashboard/widgets/<type>. --}}
@php
    $type = $widget['type'];
    $meta = \App\Domain\Dashboard\DashboardLayout::WIDGETS[$type];
    $name = __('dashboard.widgets.'.$type);
@endphp
<li class="card dash-widget dash-{{ $type }} is-{{ $widget['size'] }}" data-widget="{{ $type }}" data-size="{{ $widget['size'] }}" aria-label="{{ $name }}">
    <div class="dash-tools">
        <button type="button" class="icon-button button-small dash-grip" data-dash-grip aria-label="{{ __('dashboard.move') }}" data-tooltip="{{ __('dashboard.move') }}"><x-icon name="grip-vertical" /></button>
        <span class="dash-tools-name">{{ $name }}</span>
        <span class="button-group" role="group" aria-label="{{ __('dashboard.size') }}">
            @foreach (\App\Domain\Dashboard\DashboardLayout::SIZES as $size)
                <button type="button" class="button-quiet button-small" data-dash-size="{{ $size }}" aria-pressed="{{ $size === $widget['size'] ? 'true' : 'false' }}" aria-label="{{ __('dashboard.sizes.'.$size) }}" data-tooltip="{{ __('dashboard.sizes.'.$size) }}">{{ strtoupper($size) }}</button>
            @endforeach
        </span>
        <button type="button" class="icon-button button-small" data-dash-remove aria-label="{{ __('dashboard.remove') }}" data-tooltip="{{ __('dashboard.remove') }}"><x-icon name="x" /></button>
    </div>
    @unless (in_array($type, ['photo', 'greeting', 'drop'], true))
        <h2 class="card-head"><x-icon :name="$meta['icon']" />{{ $name }}</h2>
    @endunless
    @include('dashboard.widgets.'.$type, $widget['data'])
</li>
