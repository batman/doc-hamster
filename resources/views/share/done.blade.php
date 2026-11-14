{{-- After "Teilen → DocHamster" (point 151.2): "N Dokumente gespeichert" with thumbnails; folder and tags save at once
     (Properties module, data-autosave) for all of them; "Fertig" closes the page or returns (modules/share.js). --}}
@extends('layouts.app')

@section('title', __('share.done_title'))

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
    <x-window :title="trans_choice('share.saved', $documents->count(), ['count' => $documents->count()])" icon="circle-check" kind="share" :url="route('share.done')" :close="route('home')">
        @if ($skipped > 0)
            <p class="card-note">{{ trans_choice('share.skipped', $skipped, ['count' => $skipped]) }}</p>
        @endif

        @if ($documents->isNotEmpty())
            <ul class="view-tiles view-small share-tiles">
                @foreach ($documents as $document)
                    <li>
                        <a href="{{ route('documents.show', $document->id) }}">
                            @include('documents._thumb')
                            <span class="name">{{ $document->title }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>

            @can('documents.edit')
                <form method="post" action="{{ route('share.update') }}" data-autosave>
                    @csrf
                    <div class="field">
                        <label for="share-folder">{{ __('share.folder') }}</label>
                        <select id="share-folder" name="folder">
                            @foreach ($folders as $row)
                                <option value="{{ $row['folder']->id }}" @selected($folderId === $row['folder']->id)>{{ str_repeat('– ', $row['depth']) }}{{ $row['folder']->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($tags->isNotEmpty())
                        <fieldset>
                            <legend>{{ __('share.tags') }}</legend>
                            <div class="tags">
                                @foreach ($tags as $tag)
                                    <label class="tag-choice"><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $selected, true))><span class="tag tag-{{ $tag->id }}">{{ $tag->name }}</span></label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endif
                    <noscript><button type="submit">{{ __('documents.save') }}</button></noscript>
                </form>
            @endcan
        @endif

        <p><button type="button" data-share-finish="{{ route('home') }}"><x-icon name="check" />{{ __('share.finish') }}</button></p>
    </x-window>
    </div>
@endsection
