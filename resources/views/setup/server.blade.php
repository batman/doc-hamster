@extends('setup.layout')

@section('content')
    <h1>{{ __('setup.wizard.server_title') }}</h1>

    @if ($limitsPending)
        @push('head')
            <meta http-equiv="refresh" content="20">
        @endpush
        <p>{{ __('setup.wizard.limits_pending') }}</p>
    @else
    <p>{{ __('setup.wizard.server_text') }}</p>

    <ul class="checks">
        @foreach ($results as $result)
            <li class="check-{{ $result->status->value }}">
                <strong>{{ __('doctor.checks.'.$result->key) }}:</strong>
                {{ __('doctor.status.'.$result->status->value) }} – {{ __('doctor.details.'.$result->detail, $result->params) }}
            </li>
        @endforeach
    </ul>
    @endif

    @if ($limitsPending)
    @elseif ($canContinue)
        <a class="button" href="{{ route('setup.database') }}">{{ __('setup.wizard.next') }}</a>
    @else
        <p class="error">{{ __('setup.wizard.server_failed') }}</p>
        @if (session('limits') === 'failed')
            <p class="error">{{ __('setup.wizard.limits_failed') }}</p>
        @endif
        @if ($canFixLimits)
            <form method="post" action="{{ route('setup.limits') }}">
                @csrf
                <button type="submit">{{ __('setup.wizard.limits_fix') }}</button>
            </form>
        @endif
    @endif
@endsection
