{{-- Einstellungen → Drucker (point 127): set up the printer "docHamster", the paired computers (126/A264: paired on, last used,
     delete as row action with confirm) and the instructions. Moved here from the Druckerablage. --}}
@extends('layouts.settings')

@section('title', __('print_tray.settings_title'))

@section('page')
    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif

    <section class="card">
        <h2 class="card-head"><x-icon name="printer" />{{ __('print_tray.printers') }}
            <form method="post" action="{{ route('printers.setup') }}" class="card-action">
                @csrf
                <button type="submit" class="button-small"><x-icon name="download" />{{ __('print_tray.setup') }}</button>
            </form>
        </h2>
        <ul class="pick-list">
            @forelse ($printers as $printer)
                <li class="pick-row is-static">
                    <span class="row-icon"><x-icon name="device-desktop" /></span>
                    <span>
                        <strong>{{ $printer->name }}</strong>
                        <small>{{ __('print_tray.paired_at', ['date' => $printer->created_at->format('d.m.Y H:i')]) }} · {{ __('print_tray.last_used', ['date' => $printer->last_used_at?->format('d.m.Y H:i') ?? __('documents.never')]) }}@if ($printer->last_seen_at) · {{ __('print_tray.last_seen', ['date' => $printer->last_seen_at->format('d.m.Y H:i')]) }}@endif</small>
                        @if ($printer->silent())
                            {{-- Point 46 (A113): the computer is on but its background script may not run. --}}
                            <small class="field-hint">{{ __('print_tray.silent', ['hours' => \App\Domain\Printing\Printer::SILENT_HOURS]) }}</small>
                        @endif
                    </span>
                    @if ($printer->silent())
                        <span class="pill pill-warning">{{ __('print_tray.silent_pill') }}</span>
                    @endif
                    <span class="row-actions">
                        <button type="button" class="control-button control-danger" data-dialog-open="delete-printer-{{ $printer->id }}" aria-label="{{ __('print_tray.delete') }}: {{ $printer->name }}" data-tooltip="{{ __('print_tray.delete') }}"><x-icon name="trash" /></button>
                    </span>
                </li>
            @empty
                <x-empty tag="li" icon="printer" :text="__('empty.printers')" />
            @endforelse
        </ul>
    </section>

    @foreach ($printers as $printer)
        <div class="dialog-backdrop" data-dialog="delete-printer-{{ $printer->id }}" hidden>
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="delete-printer-{{ $printer->id }}-title">
                <h2 id="delete-printer-{{ $printer->id }}-title">{{ __('print_tray.delete_title') }}</h2>
                <p>{{ __('print_tray.delete_text') }}</p>
                <div class="dialog-actions">
                    <form method="post" action="{{ route('printers.destroy', $printer->id) }}">
                        @csrf
                        <button type="submit" class="button-danger">{{ __('print_tray.delete') }}</button>
                    </form>
                </div>
            </div>
        </div>
    @endforeach

    <section class="card">
        <h2 class="card-head"><x-icon name="list-numbers" />{{ __('print_tray.howto_title') }}</h2>
        <ol class="card-text tray-howto">
            @foreach (__('print_tray.howto') as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ol>
    </section>
@endsection
