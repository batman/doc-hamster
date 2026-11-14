{{-- "Erste Schritte" (point 213, F31): the steps tick themselves from the data (App\Domain\Dashboard\FirstSteps); each
     opens where it is done as the next window. The X hides the card for this person (dashboard.js, without JS a post);
     it disappears by itself once every step is done. $steps = list of ['key', 'done']. --}}
@php
    $icons = ['account' => 'building-bank', 'upload' => 'upload', 'import' => 'file-spreadsheet', 'phone' => 'device-mobile'];
    $links = ['account' => route('bank.index'), 'upload' => route('inbox.index'), 'import' => route('bank.import'), 'phone' => route('devices.index')];
@endphp
<section class="card first-steps" data-first-steps aria-labelledby="first-steps-title">
    <h2 class="card-head" id="first-steps-title"><x-icon name="list-numbers" />{{ __('empty.first_steps.title') }}
        <form method="post" action="{{ route('dashboard.first-steps.hide') }}" class="card-action" data-first-steps-hide>
            @csrf
            <button type="submit" class="icon-button button-small" aria-label="{{ __('empty.first_steps.hide') }}" data-tooltip="{{ __('empty.first_steps.hide') }}"><x-icon name="x" /></button>
        </form>
    </h2>
    <p class="card-text">{{ __('empty.first_steps.intro') }}</p>
    <ul class="pick-list">
        @foreach ($steps as $step)
            <li class="pick-row is-static" @if ($step['done']) data-done @endif>
                <span class="row-icon"><x-icon :name="$step['done'] ? 'circle-check' : $icons[$step['key']]" /></span>
                <span><strong><a href="{{ $links[$step['key']] }}" data-window>{{ __('empty.first_steps.steps.'.$step['key']) }}</a></strong></span>
                @if ($step['done'])
                    <span class="pill pill-ok">{{ __('empty.first_steps.done') }}</span>
                @endif
            </li>
        @endforeach
    </ul>
</section>
