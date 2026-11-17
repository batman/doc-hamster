{{-- The entries of a menu window (components/menu-window.blade.php): icon, name, short description, red count, chevron. --}}
<ul class="menu-entries is-{{ $shown }}" data-menu-entries="{{ $view }}">
    @foreach ($entries as $entry)
        <li>
            <a class="menu-entry tooltip-right" href="{{ $entry['href'] }}" data-window data-tooltip="{{ $entry['title'] }}">
                <x-icon :name="$entry['icon']" />
                <span class="menu-entry-text">
                    <span class="menu-entry-name">{{ $entry['title'] }}</span>
                    @if (($entry['text'] ?? '') !== '')
                        <small class="menu-entry-desc">{{ $entry['text'] }}</small>
                    @endif
                </span>
                @if (($entry['badge'] ?? null) !== null)
                    @php $count = (int) ($entry['count'] ?? 0); @endphp
                    <span class="badge badge-alert" data-badge="{{ $entry['badge'] }}" @if ($count === 0) hidden @endif>{{ $count > 99 ? '99+' : $count }}</span>
                @endif
                <span class="go"><x-icon name="chevron-right" /></span>
            </a>
        </li>
    @endforeach
</ul>
