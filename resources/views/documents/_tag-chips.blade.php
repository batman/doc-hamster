<div class="chips">
    @foreach ($tags as $tag)
        <label class="chip"><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $selected, true))> <span>{{ $tag->name }}</span></label>
    @endforeach
</div>
