<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Services\PlaylistService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\Tags\Tag;

class AddGroupsToCustomPlaylist implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 60 * 10;

    /**
     * Create a new job instance.
     *
     * @param  array<int>  $groupIds  IDs of Group or Category records to process
     * @param  array<string, mixed>  $data  Form data: mode, category, new_category
     */
    public function __construct(
        public int $userId,
        public array $groupIds,
        public int $customPlaylistId,
        public array $data,
        public string $type = 'channel',
    ) {}

    /**
     * Execute the job.
     *
     * Resolves each group's items and tag up front, then fans the actual work out
     * to parallel AddItemsToCustomPlaylistChunk jobs so very large groups (100k+
     * channels) finish quickly and never exceed a single job's timeout.
     */
    public function handle(): void
    {
        $playlist = CustomPlaylist::findOrFail($this->customPlaylistId);

        $isSeries = $this->type === 'series';
        $tagType = $isSeries ? $playlist->uuid.'-category' : $playlist->uuid;
        $relation = $isSeries ? 'series' : 'channels';

        $mode = $this->data['mode'] ?? 'select';
        $tagName = match ($mode) {
            'select' => $this->data['category'] ?? null,
            'create' => $this->data['new_category'] ?? null,
            default => null,
        };

        // Tag creation is not race-safe, so all tags are created here before the
        // parallel chunk jobs run; the chunks then only look up existing tags
        if ($mode !== 'original' && $tagName) {
            $playlist->attachTag(Tag::findOrCreate($tagName, $tagType));
        }

        $chunkJobs = [];
        foreach ($this->groupIds as $groupId) {
            $group = $isSeries
                ? Category::find($groupId)
                : Group::find($groupId);

            if (! $group) {
                continue;
            }

            // For 'original' mode, derive the tag name from the group/category model
            $groupTagName = $tagName;
            if ($mode === 'original') {
                $groupTagName = $group->name ?? $group->name_internal ?? null;
                if (! $groupTagName) {
                    continue;
                }
                $playlist->attachTag(Tag::findOrCreate($groupTagName, $tagType));
            }

            foreach ($group->$relation()->pluck('id')->chunk(PlaylistService::CUSTOM_PLAYLIST_CHUNK_SIZE) as $chunk) {
                $chunkJobs[] = new AddItemsToCustomPlaylistChunk(
                    customPlaylistId: $this->customPlaylistId,
                    itemIds: $chunk->values()->all(),
                    tagName: $groupTagName,
                    type: $this->type,
                );
            }
        }

        AddItemsToCustomPlaylist::dispatchChunkedBatch($chunkJobs, $this->userId);
    }
}
