@extends('setup.layout')

@section('content')
    <h1>{{ __('setup.wizard.server_title') }}</h1>
    <p>{{ __('setup.wizard.server_text') }}</p>

    <ul class="checks">
        @foreach ($results as $result)
            <li class="check-{{ $result->status->value }}">
                <strong>{{ __('doctor.checks.'.$result->key) }}:</strong>
                {{ __('doctor.status.'.$result->status->value) }} – {{ __('doctor.details.'.$result->detail, $result->params) }}
            </li>
        @endforeach
    </ul>

    @if ($canContinue)
        <a class="button" href="{{ route('setup.database') }}">{{ __('setup.wizard.next') }}</a>
    @else
        <p class="error">{{ __('setup.wizard.server_failed') }}</p>
    @endif
@endsection
