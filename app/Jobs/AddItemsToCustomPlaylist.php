<?php

namespace App\Jobs;

use App\Models\CustomPlaylist;
use App\Models\User;
use App\Services\PlaylistService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

class AddItemsToCustomPlaylist implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 60 * 10;

    /**
     * Create a new job instance.
     *
     * @param  array<int>  $itemIds  IDs of Channel or Series records to process
     * @param  array<string, mixed>  $data  Form data: mode, category, new_category
     * @param  string  $context  Notification wording: 'playlist', 'group' or 'category'
     */
    public function __construct(
        public int $userId,
        public array $itemIds,
        public int $customPlaylistId,
        public array $data,
        public string $type = 'channel',
        public string $context = 'playlist',
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $playlist = CustomPlaylist::findOrFail($this->customPlaylistId);

        $mode = $this->data['mode'] ?? 'select';
        $tagName = match ($mode) {
            'select' => $this->data['category'] ?? null,
            'create' => $this->data['new_category'] ?? null,
            default => null,
        };

        // Tag creation is not race-safe, so every tag the parallel chunk jobs will
        // need is created up front; the chunks then only look up existing tags
        PlaylistService::ensureCustomPlaylistTagsExist(
            playlist: $playlist,
            itemIds: $this->itemIds,
            tagName: $tagName,
            useOriginalTagNames: $mode === 'original',
            type: $this->type,
        );

        $chunkJobs = [];
        foreach (array_chunk($this->itemIds, PlaylistService::CUSTOM_PLAYLIST_CHUNK_SIZE) as $chunk) {
            $chunkJobs[] = new AddItemsToCustomPlaylistChunk(
                customPlaylistId: $this->customPlaylistId,
                itemIds: $chunk,
                tagName: $tagName,
                useOriginalTagNames: $mode === 'original',
                type: $this->type,
            );
        }

        self::dispatchChunkedBatch($chunkJobs, $this->userId, $this->context);
    }

    /**
     * Dispatch chunk jobs as a Bus::batch() so they run in parallel across
     * available queue workers, notifying the user when the batch finishes.
     *
     * @param  array<AddItemsToCustomPlaylistChunk>  $chunkJobs
     */
    public static function dispatchChunkedBatch(array $chunkJobs, int $userId, string $context = 'playlist'): void
    {
        if ($chunkJobs === []) {
            self::notifyCompleted($userId, $context);

            return;
        }

        Bus::batch($chunkJobs)
            ->name('add-items-to-custom-playlist')
            ->then(function () use ($userId, $context): void {
                AddItemsToCustomPlaylist::notifyCompleted($userId, $context);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($userId): void {
                AddItemsToCustomPlaylist::notifyFailed($userId, $e);
            })
            ->dispatch();
    }

    /**
     * Notify the user that all items have been processed.
     */
    public static function notifyCompleted(int $userId, string $context = 'playlist'): void
    {
        $user = User::find($userId);
        if (! $user) {
            return;
        }

        [$title, $body] = match ($context) {
            'group' => [
                __('Items added to custom group'),
                __('The selected items have been added to the chosen custom group.'),
            ],
            'category' => [
                __('Items added to custom category'),
                __('The selected items have been added to the chosen custom category.'),
            ],
            default => [
                __('Items added to custom playlist'),
                __('The selected items have been added to the chosen custom playlist.'),
            ],
        };

        Notification::make()
            ->success()
            ->title($title)
            ->body($body)
            ->broadcast($user);

        Notification::make()
            ->success()
            ->title($title)
            ->body($body)
            ->sendToDatabase($user);
    }

    /**
     * Notify the user that the batch failed. Safe for use inside batch closures
     * where $this cannot be serialized.
     */
    public static function notifyFailed(int $userId, Throwable $e): void
    {
        $user = User::find($userId);
        if (! $user) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('Custom playlist update failed'))
            ->body(__('Please view your notifications for details.'))
            ->broadcast($user);

        Notification::make()
            ->danger()
            ->title(__('Custom playlist update failed'))
            ->body($e->getMessage())
            ->sendToDatabase($user);
    }
}
