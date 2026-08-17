<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Series;
use App\Models\User;
use App\Services\PlaylistService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;

class DetachItemsFromCustomPlaylist implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 60 * 30;

    /**
     * Create a new job instance.
     *
     * @param  array<int>  $itemIds  IDs of Channel or Series records to detach
     */
    public function __construct(
        public int $userId,
        public array $itemIds,
        public int $customPlaylistId,
        public string $type = 'channel',
    ) {}

    /**
     * Execute the job.
     *
     * Removes the playlist's custom group tags from the items and detaches them
     * from the playlist using set-based queries, so detaching very large
     * selections (100k+ items) cannot hit an HTTP or job timeout.
     */
    public function handle(): void
    {
        $playlist = CustomPlaylist::findOrFail($this->customPlaylistId);
        $user = User::findOrFail($this->userId);

        $isSeries = $this->type === 'series';
        $tagType = $isSeries ? $playlist->uuid.'-category' : $playlist->uuid;
        $relation = $isSeries ? $playlist->series() : $playlist->channels();
        $morphClass = $isSeries ? (new Series)->getMorphClass() : (new Channel)->getMorphClass();

        foreach (array_chunk($this->itemIds, PlaylistService::CUSTOM_PLAYLIST_CHUNK_SIZE) as $chunk) {
            DB::table('taggables')
                ->where('taggable_type', $morphClass)
                ->whereIn('taggable_id', $chunk)
                ->whereIn('tag_id', Tag::query()->where('type', $tagType)->select('id'))
                ->delete();

            $relation->detach($chunk);
        }

        Notification::make()
            ->success()
            ->title(__('Items detached from custom playlist'))
            ->body(__('The selected items have been detached from the custom playlist.'))
            ->broadcast($user);

        Notification::make()
            ->success()
            ->title(__('Items detached from custom playlist'))
            ->body(__('The selected items have been detached from the custom playlist.'))
            ->sendToDatabase($user);
    }
}
