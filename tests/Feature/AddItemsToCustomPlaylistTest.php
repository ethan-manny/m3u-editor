<?php

use App\Events\PlaylistCreated;
use App\Events\PlaylistUpdated;
use App\Jobs\AddItemsToCustomPlaylist;
use App\Models\Category;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Tags\Tag;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([PlaylistCreated::class, PlaylistUpdated::class]);
    Notification::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->customPlaylist = CustomPlaylist::factory()->create(['user_id' => $this->user->id]);
});

it('syncs the selected channels to the custom playlist', function () {
    $channels = Channel::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: $channels->pluck('id')->all(),
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'playlist' => $this->customPlaylist->id],
        type: 'channel',
    ))->handle();

    foreach ($channels as $channel) {
        expect($this->customPlaylist->channels()->where('channels.id', $channel->id)->exists())->toBeTrue();
    }
});

it('tags the selected channels with the chosen group in select mode', function () {
    $channels = Channel::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: $channels->pluck('id')->all(),
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'playlist' => $this->customPlaylist->id, 'category' => 'My Group Tag'],
        type: 'channel',
    ))->handle();

    foreach ($channels as $channel) {
        $channel->refresh();
        expect($channel->tags->pluck('name')->all())->toContain('My Group Tag');
    }

    expect($this->customPlaylist->groupTags()->get()->pluck('name')->all())->toContain('My Group Tag');
});

it('creates and applies a new tag in create mode', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channel->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'create', 'playlist' => $this->customPlaylist->id, 'new_category' => 'Brand New Tag'],
        type: 'channel',
    ))->handle();

    $channel->refresh();
    expect($channel->tags->pluck('name')->all())->toContain('Brand New Tag');
});

it('tags each channel with its own original group name in original mode', function () {
    $sportsChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group' => 'Sports',
    ]);
    $newsChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group' => 'News',
    ]);
    $ungroupedChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group' => null,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$sportsChannel->id, $newsChannel->id, $ungroupedChannel->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original', 'playlist' => $this->customPlaylist->id],
        type: 'channel',
    ))->handle();

    expect($sportsChannel->refresh()->tags->pluck('name')->all())->toContain('Sports');
    expect($newsChannel->refresh()->tags->pluck('name')->all())->toContain('News');

    // Channels without a group are synced but left untagged
    expect($this->customPlaylist->channels()->where('channels.id', $ungroupedChannel->id)->exists())->toBeTrue();
    expect($ungroupedChannel->refresh()->tags->where('type', $this->customPlaylist->uuid)->count())->toBe(0);
});

it('replaces the previous custom group tag and preserves pivot values when re-adding', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $this->customPlaylist->channels()->attach($channel->id, ['channel_number' => 42]);
    $oldTag = Tag::findOrCreate('Old Group', $this->customPlaylist->uuid);
    $this->customPlaylist->attachTag($oldTag);
    $channel->attachTag($oldTag);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channel->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'playlist' => $this->customPlaylist->id, 'category' => 'New Group'],
        type: 'channel',
    ))->handle();

    $channel->refresh();
    $tagNames = $channel->tags->pluck('name')->all();

    expect($tagNames)->toContain('New Group')
        ->and($tagNames)->not->toContain('Old Group');

    $pivot = $this->customPlaylist->channels()->where('channels.id', $channel->id)->first()->pivot;
    expect($pivot->channel_number)->toEqual(42);
});

it('does not touch tags belonging to another custom playlist', function () {
    $otherPlaylist = CustomPlaylist::factory()->create(['user_id' => $this->user->id]);
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $otherTag = Tag::findOrCreate('Other Playlist Group', $otherPlaylist->uuid);
    $channel->attachTag($otherTag);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channel->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'playlist' => $this->customPlaylist->id, 'category' => 'New Group'],
        type: 'channel',
    ))->handle();

    $channel->refresh();
    $tagNames = $channel->tags->pluck('name')->all();

    expect($tagNames)->toContain('New Group')
        ->and($tagNames)->toContain('Other Playlist Group');
});

it('syncs series and tags them with their category name in original mode', function () {
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Drama',
    ]);

    $seriesItems = Series::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $category->id,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: $seriesItems->pluck('id')->all(),
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original', 'playlist' => $this->customPlaylist->id],
        type: 'series',
    ))->handle();

    foreach ($seriesItems as $series) {
        expect($this->customPlaylist->series()->where('series.id', $series->id)->exists())->toBeTrue();
        expect($series->refresh()->tags->pluck('name')->all())->toContain('Drama');
    }
});
