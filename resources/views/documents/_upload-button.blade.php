{{-- "Hochladen" as a file button for an empty state (point 213): the Posteingang's upload form (inbox.js sends it and
     reloads the window it sits in); $folder = where the files go, null = the Posteingang. --}}
<form method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data" data-inbox-upload data-failed="{{ __('documents.bulk.failed') }}">
    @csrf
    @if (($folder ?? null) !== null)<input type="hidden" name="folder" value="{{ $folder }}">@endif
    <label class="button button-small file-button">
        <input class="visually-hidden" type="file" name="files[]" multiple accept="image/*,application/pdf">
        <x-icon name="upload" />{{ __('inbox.upload') }}
    </label>
    <noscript><button type="submit" class="button-small">{{ __('inbox.upload') }}</button></noscript>
</form>
