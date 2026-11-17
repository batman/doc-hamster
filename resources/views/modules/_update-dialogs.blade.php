{{-- F16: the update dialog of each installed module with a newer version – beside the version list, so it comes along
     when "Update" reloads that list by Ajax. New rights are asked again (tick). --}}
@can('modules.manage')
@foreach (app(\App\Modules\InstalledModules::class)->all() as $key => $entry)
    @php
        $m = $entry['manifest'];
        $latest = $entry['latest'] ?? null;
        $added = $latest === null ? [] : array_values(array_diff($latest['rights'], $entry['rights']));
    @endphp
    @if ($latest !== null && version_compare($latest['version'], (string) $m['version'], '>'))
        <div class="dialog-backdrop" data-dialog="module-update-{{ $key }}" hidden>
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="module-update-{{ $key }}-title">
                <form method="post" action="{{ route('modules.update', $key) }}" data-confirm-sudo>
                    @csrf
                    <h2 id="module-update-{{ $key }}-title">{{ __('modules.update_title', ['name' => $m['name'], 'version' => $latest['version']]) }}</h2>
                    <p>{{ __('modules.update_text') }}</p>
                    @if ($added !== [])
                        <p>{{ __('modules.update_new_rights') }}</p>
                        <ul>
                            @foreach ($added as $right)
                                <li>{{ __('modules.rights.'.$right) }}<input type="hidden" name="rights[]" value="{{ $right }}"></li>
                            @endforeach
                        </ul>
                        <label class="check"><input type="checkbox" name="accept" value="1" required> {{ __('modules.accept') }}</label>
                    @endif
                    <div class="dialog-actions">
                        <button type="submit"><x-icon name="refresh" />{{ __('modules.update') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endforeach
@endcan
