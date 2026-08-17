<?php

use App\Events\PlaylistCreated;
use App\Events\PlaylistUpdated;
use App\Jobs\DetachItemsFromCustomPlaylist;
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

it('detaches the selected channels and removes their custom group tags', function () {
    $channels = Channel::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $tag = Tag::findOrCreate('My Group', $this->customPlaylist->uuid);
    $this->customPlaylist->attachTag($tag);
    foreach ($channels as $channel) {
        $this->customPlaylist->channels()->attach($channel->id);
        $channel->attachTag($tag);
    }

    (new DetachItemsFromCustomPlaylist(
        userId: $this->user->id,
        itemIds: $channels->pluck('id')->all(),
        customPlaylistId: $this->customPlaylist->id,
        type: 'channel',
    ))->handle();

    expect($this->customPlaylist->channels()->count())->toBe(0);

    foreach ($channels as $channel) {
        $channel->refresh();
        expect($channel->tags->where('type', $this->customPlaylist->uuid)->count())->toBe(0);
    }
});

it('leaves unselected channels and unrelated tags untouched', function () {
    $keptChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);
    $removedChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $tag = Tag::findOrCreate('My Group', $this->customPlaylist->uuid);
    $this->customPlaylist->attachTag($tag);
    foreach ([$keptChannel, $removedChannel] as $channel) {
        $this->customPlaylist->channels()->attach($channel->id);
        $channel->attachTag($tag);
    }

    $otherPlaylist = CustomPlaylist::factory()->create(['user_id' => $this->user->id]);
    $otherTag = Tag::findOrCreate('Other Playlist Group', $otherPlaylist->uuid);
    $removedChannel->attachTag($otherTag);

    (new DetachItemsFromCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$removedChannel->id],
        customPlaylistId: $this->customPlaylist->id,
        type: 'channel',
    ))->handle();

    expect($this->customPlaylist->channels()->where('channels.id', $keptChannel->id)->exists())->toBeTrue();
    expect($this->customPlaylist->channels()->where('channels.id', $removedChannel->id)->exists())->toBeFalse();

    expect($keptChannel->refresh()->tags->pluck('name')->all())->toContain('My Group');

    $removedTagNames = $removedChannel->refresh()->tags->pluck('name')->all();
    expect($removedTagNames)->not->toContain('My Group')
        ->and($removedTagNames)->toContain('Other Playlist Group');
});

it('detaches series and removes their category tags', function () {
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

    $tag = Tag::findOrCreate('Drama', $this->customPlaylist->uuid.'-category');
    $this->customPlaylist->attachTag($tag);
    foreach ($seriesItems as $series) {
        $this->customPlaylist->series()->attach($series->id);
        $series->attachTag($tag);
    }

    (new DetachItemsFromCustomPlaylist(
        userId: $this->user->id,
        itemIds: $seriesItems->pluck('id')->all(),
        customPlaylistId: $this->customPlaylist->id,
        type: 'series',
    ))->handle();

    expect($this->customPlaylist->series()->count())->toBe(0);

    foreach ($seriesItems as $series) {
        $series->refresh();
        expect($series->tags->where('type', $this->customPlaylist->uuid.'-category')->count())->toBe(0);
    }
});
