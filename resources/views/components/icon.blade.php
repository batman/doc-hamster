@props(['name'])
@php
    // Tabler icon from resources/icons/tabler, inlined so it takes the text colour (currentColor).
    $path = resource_path('icons/tabler/'.$name.'.svg');
    if (preg_match('/^[a-z0-9-]+$/', $name) !== 1 || ! is_file($path)) {
        throw new InvalidArgumentException("Unknown icon: {$name}");
    }
@endphp
{!! str_replace('<svg ', '<svg class="icon" aria-hidden="true" focusable="false" ', trim((string) file_get_contents($path))) !!}
