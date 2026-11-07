{{-- Kept by the service worker (public/sw.js) and shown without a connection. Holds no personal data
     and no session token: photos taken here wait on the phone and are sent later (public/js/app.js). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#30302e">
    <title>{{ __('app.shell.offline_title') }} – {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('dochamster.version') }}">
    <link rel="manifest" href="{{ route('app.manifest') }}">
    <script src="{{ asset('js/app.js') }}?v={{ config('dochamster.version') }}"></script>
    <script src="{{ asset('js/hamster.js') }}?v={{ config('dochamster.version') }}" defer></script>
</head>
<body class="offline-page">
    <main id="main" class="page">
        <div class="empty-state">
            <div class="offline-hamster" data-hamster="sleep"></div>
            <h1>{{ __('app.shell.offline_title') }}</h1>
            <p>{{ __('app.shell.offline_text') }}</p>
            <p><a class="button button-quiet" href="{{ route('home') }}">{{ __('app.shell.retry') }}</a></p>
        </div>
    </main>
    <nav class="app-bar" aria-label="{{ __('app.bar.label') }}">
        <form class="app-bar-photo" method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data" data-queue-upload>
            <label class="app-bar-item app-bar-main">
                <input class="visually-hidden" type="file" name="files[]" accept="image/*,application/pdf" capture="environment" data-camera>
                <x-icon name="camera" /><span>{{ __('app.bar.photo') }}</span>
            </label>
        </form>
    </nav>
    <div class="queue-status" role="status" hidden data-home="{{ route('home') }}"
        data-sending="{{ __('app.queue.sending') }}" data-sent="{{ __('app.queue.sent') }}"
        data-waiting="{{ trans_choice('app.queue.waiting', 1) }}" data-waiting-many="{{ trans_choice('app.queue.waiting', 2) }}" data-login="{{ __('app.queue.login') }}">
        <span class="queue-hamster" aria-hidden="true"></span><span class="queue-text"></span>
    </div>
</body>
</html>
