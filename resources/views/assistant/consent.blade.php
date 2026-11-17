{{-- The consent page (D-040): an assistant asks to connect – who asks (name, the address it returns to), for which
     organisation and person, "Nur lesen" or "Lesen + Verknüpfen", what it can never do; Erlauben / Ablehnen. Needs a
     fresh confirmation (point 212). The answer goes back to the assistant through assistant/oauth-back. --}}
@extends('layouts.app')

@section('title', __('assistant.consent.title'))

@section('content')
    <h1>{{ __('assistant.consent.heading', ['name' => $client->name]) }}</h1>
    <section class="card">
        <p class="card-text">{{ __('assistant.consent.text', ['name' => $client->name, 'host' => $host, 'person' => $person, 'org' => $organisation]) }}</p>
        <form method="post" action="{{ route('oauth.approve') }}" data-confirm-sudo>
            @csrf
            @foreach ($fields as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <fieldset>
                <legend>{{ __('assistant.consent.scope') }}</legend>
                @include('documents._search-choice', ['name' => 'scope', 'value' => 'read', 'options' => __('account.keys.scopes'), 'icons' => ['read' => 'eye', 'link' => 'link'], 'toggle' => false, 'label' => __('assistant.consent.scope')])
            </fieldset>
            <div class="notice">{{ __('assistant.consent.never') }}</div>
            <div class="actions">
                <button type="submit" name="decision" value="allow"><x-icon name="check" />{{ __('assistant.consent.allow') }}</button>
                <button type="submit" name="decision" value="deny" class="button-quiet"><x-icon name="x" />{{ __('assistant.consent.deny') }}</button>
            </div>
        </form>
    </section>
@endsection
