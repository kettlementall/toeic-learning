{{-- Audio for a listening group. Practice/review: native controls. Mock: driven by the page's player (M4). --}}
@if ($group && ! empty($group->audio))
    @if (($mode ?? 'practice') !== 'mock')
        <div class="mb-3 space-y-1">
            @foreach ($group->audio as $track)
                <audio controls preload="none" class="w-full" src="{{ route('practice.media', ['path' => $track]) }}"></audio>
            @endforeach
        </div>
    @endif
    @if ($group->image_path)
        <img src="{{ route('practice.media', ['path' => $group->image_path]) }}" alt="Photo for this question" class="rounded-lg border mb-3 max-h-80">
    @endif
@elseif ($group && $group->image_path)
    <img src="{{ route('practice.media', ['path' => $group->image_path]) }}" alt="Photo for this question" class="rounded-lg border mb-3 max-h-80">
@endif
