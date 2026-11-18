{{-- Objektkachel (point 6): a document or booking a proposal names – picture or symbol, its label, name, date and
     amount. A click opens it as a window on the right (with ?proposal=, so its head offers ✓ / bin, A62); a thing that
     is gone is a plain tile without link. --}}
@php($tag = $object['url'] ? 'a' : 'span')
<{{ $tag }} class="object-tile @unless ($object['url']) is-gone @endunless" @if ($object['url']) href="{{ $object['url'] }}{{ str_contains($object['url'], '?') ? '&' : '?' }}proposal={{ $proposal->id }}" data-window @endif>
    <span class="thumb-frame">
        @if ($object['thumb'])
            <img class="thumb" src="{{ $object['thumb'] }}" alt="" loading="lazy" draggable="false">
        @else
            <x-icon :name="$object['icon']" />
        @endif
    </span>
    <span class="object-tile-body">
        <small>{{ $object['label'] }}</small>
        <strong>{{ $object['title'] }}</strong>
        @if ($object['meta'] !== '')<small>{{ $object['meta'] }}</small>@endif
    </span>
</{{ $tag }}>
