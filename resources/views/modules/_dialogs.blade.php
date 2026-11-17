{{-- The module dialogs of the Software page (D-041): install (foreign-code notice, the rights the module asks for,
     agreement tick, fresh password), update (new rights asked again), remove (with or without its data, F18 a). --}}
@foreach ($found as $i => $hit)
    @php $m = $hit['manifest']; @endphp
    <div class="dialog-backdrop" data-dialog="module-install-{{ $i }}" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="module-install-{{ $i }}-title">
            <form method="post" action="{{ route('modules.install') }}" data-confirm-sudo>
                @csrf
                <input type="hidden" name="source" value="{{ $hit['source'] }}">
                <input type="hidden" name="key" value="{{ $m['key'] }}">
                <h2 id="module-install-{{ $i }}-title">{{ __('modules.install_title', ['name' => $m['name']]) }}</h2>
                <p>{{ $m['description'] }}</p>
                <p class="field-hint">{{ __('modules.by', ['provider' => $m['provider'], 'version' => $m['version']]) }} · {{ $hit['source'] }}</p>
                <p class="error" role="note">{{ __('modules.foreign') }}</p>
                @if ($m['rights'] === [])
                    <p>{{ __('modules.asks_nothing') }}</p>
                @else
                    <p>{{ __('modules.asks') }}</p>
                    <ul>
                        @foreach ($m['rights'] as $right)
                            <li>{{ __('modules.rights.'.$right) }}<input type="hidden" name="rights[]" value="{{ $right }}"></li>
                        @endforeach
                    </ul>
                @endif
                <label class="check"><input type="checkbox" name="accept" value="1" required> {{ __('modules.accept') }}</label>
                <div class="dialog-actions">
                    <button type="submit"><x-icon name="download" />{{ __('modules.install') }}</button>
                </div>
            </form>
        </div>
    </div>
@endforeach
@foreach ($installed as $key => $entry)
    @php($m = $entry['manifest'])
    <div class="dialog-backdrop" data-dialog="module-remove-{{ $key }}" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="module-remove-{{ $key }}-title">
            <form method="post" action="{{ route('modules.remove', $key) }}" data-confirm-sudo>
                @csrf
                <h2 id="module-remove-{{ $key }}-title">{{ __('modules.remove_title', ['name' => $m['name']]) }}</h2>
                <p>{{ __('modules.remove_text') }}</p>
                <label class="check"><input type="checkbox" name="with_data" value="1"> {{ __('modules.with_data') }}</label>
                <div class="dialog-actions">
                    <button type="submit" class="button-danger"><x-icon name="trash" />{{ __('modules.remove') }}</button>
                </div>
            </form>
        </div>
    </div>
@endforeach
