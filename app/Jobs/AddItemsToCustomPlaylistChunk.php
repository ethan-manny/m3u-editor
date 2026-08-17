<?php

namespace App\Jobs;

use App\Models\CustomPlaylist;
use App\Services\PlaylistService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AddItemsToCustomPlaylistChunk implements ShouldQueue
{
    use Batchable, Queueable;

    public $tries = 1;

    public $timeout = 60 * 10;

    /**
     * Create a new job instance.
     *
     * @param  array<int>  $itemIds  IDs of the Channel or Series records in this chunk
     * @param  string|null  $tagName  Custom group tag applied to all items (null: sync only)
     * @param  bool  $useOriginalTagNames  Tag each item with its own group/category name instead
     */
    public function __construct(
        public int $customPlaylistId,
        public array $itemIds,
        public ?string $tagName = null,
        public bool $useOriginalTagNames = false,
        public string $type = 'channel',
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $playlist = CustomPlaylist::find($this->customPlaylistId);
        if (! $playlist) {
            return;
        }

        PlaylistService::syncItemsToCustomPlaylist(
            playlist: $playlist,
            itemIds: $this->itemIds,
            tagName: $this->tagName,
            useOriginalTagNames: $this->useOriginalTagNames,
            type: $this->type,
        );
    }
}
