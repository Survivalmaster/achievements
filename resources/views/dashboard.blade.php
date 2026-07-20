<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Achievement Tracker</title>
    <link rel="stylesheet" href="{{ asset('assets/tracker.css') }}?v={{ filemtime(public_path('assets/tracker.css')) }}">
    <script src="{{ asset('assets/tracker.js') }}?v={{ filemtime(public_path('assets/tracker.js')) }}" defer></script>
</head>
<body class="tracker-body" data-quick-refresh-url="{{ route('sync.quick-refresh') }}" @if ($currentGame) data-current-game-id="{{ $currentGame->id }}" @endif>
    <div class="tracker-shell">
        <aside class="games-panel">
            <div class="panel-top">
                <div>
                    <p class="eyebrow">Platform library</p>
                    <h1>Achievements</h1>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="icon-button" type="submit" title="Lock tracker" aria-label="Lock tracker">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10V8a6 6 0 1 1 12 0v2h1a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1h1Zm2 0h8V8a4 4 0 0 0-8 0v2Z"/></svg>
                    </button>
                </form>
            </div>

            <a href="{{ route('dashboard', ['platform_filter' => $platformFilter]) }}" class="dashboard-return {{ $mode === 'overview' ? 'active' : '' }}">
                <span>Dashboard</span>
                <strong>{{ $overview['completion'] }}%</strong>
            </a>

            <div class="sync-actions">
                <form method="POST" action="{{ route('sync.library') }}" class="sync-row">
                    @csrf
                    <button type="submit">Steam Library</button>
                </form>

                <form method="POST" action="{{ route('sync.achievements') }}" class="sync-row" data-sync-achievements data-unsynced-games="{{ $unsyncedGames }}">
                    @csrf
                    <button type="submit">Steam Achievements</button>
                    <span class="sr-only" data-sync-left>{{ $unsyncedGames }} left</span>
                </form>
            </div>

            <form method="POST" action="{{ route('sync.refresh-all') }}" class="refresh-all-row" data-refresh-all-games data-refreshable-games="{{ $refreshableGames }}">
                @csrf
                <button type="submit">Refresh Steam Games</button>
            </form>

            <form method="POST" action="{{ route('spoilers.update') }}" class="spoiler-toggle">
                @csrf
                <label>
                    <input type="checkbox" name="spoiler_safe" value="1" @checked($spoilerSafe) onchange="this.form.submit()">
                    <span>Spoiler-safe secrets</span>
                </label>
            </form>

            <section class="platform-link-card">
                <div class="tool-heading">
                    <h3>PlayStation</h3>
                    @if ($psnAccount)
                        <span class="platform-badge platform-psn"><i></i>Linked</span>
                    @endif
                </div>
                @if ($psnAccount)
                    <form method="POST" action="{{ route('psn.sync') }}">
                        @csrf
                        <button type="submit">Sync PlayStation</button>
                    </form>
                    <small>{{ $psnAccount->synced_at ? 'Synced '.$psnAccount->synced_at->diffForHumans() : 'Ready to sync trophies' }}</small>
                @else
                    <form method="POST" action="{{ route('psn.link') }}">
                        @csrf
                        <input name="npsso" type="password" placeholder="Paste NPSSO token" autocomplete="off">
                        <button type="submit">Link PSN</button>
                    </form>
                @endif
            </section>

            <section class="platform-link-card compact">
                <div class="tool-heading">
                    <h3>More Platforms</h3>
                </div>
                @foreach ([\App\Models\SteamGame::PLATFORM_EPIC, \App\Models\SteamGame::PLATFORM_EA] as $externalPlatform)
                    @php($externalAccount = $externalAccounts->get($externalPlatform))
                    <form method="POST" action="{{ route('platforms.link', $externalPlatform) }}" class="platform-mini-form">
                        @csrf
                        <span class="platform-badge platform-{{ $externalPlatform }}"><i></i>{{ \App\Models\SteamGame::PLATFORMS[$externalPlatform] }}</span>
                        <input name="display_name" value="{{ $externalAccount?->display_name }}" placeholder="Account name">
                        <button type="submit">{{ $externalAccount ? 'Save' : 'Add' }}</button>
                    </form>
                @endforeach
                <small>Sync pending API support.</small>
            </section>

            <div class="game-filters" aria-label="Game filters">
                <a class="{{ $mode === 'overview' ? 'active' : '' }}" href="{{ route('dashboard', ['platform_filter' => $platformFilter]) }}">
                    <span>Dashboard</span>
                    <strong>{{ $overview['completion'] }}%</strong>
                </a>
                @foreach ([
                    'all' => 'All',
                    'in_progress' => 'In progress',
                    'completed' => 'Completed',
                    'unchecked' => 'Unchecked',
                    'archived' => 'Archived',
                ] as $key => $label)
                    <a class="{{ $gameFilter === $key ? 'active' : '' }}" href="{{ route('dashboard', ['game_filter' => $key, 'platform_filter' => $platformFilter]) }}">
                        <span>{{ $label }}</span>
                        <strong>{{ $gameCounts[$key] }}</strong>
                    </a>
                @endforeach
            </div>

            <div class="platform-filters" aria-label="Platform filters">
                <a class="{{ $platformFilter === 'all' ? 'active' : '' }}" href="{{ route('dashboard', ['game_filter' => $gameFilter, 'platform_filter' => 'all']) }}">
                    <span class="platform-badge platform-all"><i></i>All</span>
                    <strong>{{ $platformCounts['all'] }}</strong>
                </a>
                @foreach ($platforms as $key => $label)
                    <a class="{{ $platformFilter === $key ? 'active' : '' }}" href="{{ route('dashboard', ['game_filter' => $gameFilter, 'platform_filter' => $key]) }}">
                        <span class="platform-badge platform-{{ $key }}"><i></i>{{ $label }}</span>
                        <strong>{{ $platformCounts[$key] ?? 0 }}</strong>
                    </a>
                @endforeach
            </div>

            <input class="game-search" type="search" placeholder="Search games" data-game-search>

            <nav class="game-list" aria-label="Games">
                @forelse ($games as $game)
                    <form method="POST" action="{{ route('games.current', $game) }}" class="game-tile {{ $game->is_current ? 'active' : '' }} {{ $game->is_completed ? 'completed' : '' }}" data-game-name="{{ strtolower($game->name) }}">
                        @csrf
                        <input type="hidden" name="game_filter" value="{{ $gameFilter }}">
                        <input type="hidden" name="platform_filter" value="{{ $platformFilter }}">
                        <input type="hidden" name="filter" value="{{ $filter }}">
                        <button type="submit">
                            <span class="game-icon">
                                @if ($game->icon_url)
                                    <img src="{{ $game->icon_url }}" alt="">
                                @else
                                    <span>{{ strtoupper(substr($game->name, 0, 1)) }}</span>
                                @endif
                            </span>
                            <span class="game-copy">
                                <strong>{{ $game->name }}</strong>
                                <small>
                                    <span class="platform-mini">{{ $game->platform_label }}</span>
                                    {{ $game->achievements_unlocked }}/{{ $game->achievements_total }} unlocked
                                    @if ($game->last_played_at)
                                        <span class="played-date">{{ $game->last_played_label }}</span>
                                    @endif
                                    @if ($game->is_current)
                                        <span class="main-badge">Main</span>
                                    @endif
                                    @if ($game->is_completed)
                                        <span class="completed-badge">Completed</span>
                                    @endif
                                    @if ($game->achievements_synced_at && $game->achievements_synced_at->lt(now()->subDay()))
                                        <span class="stale-badge">Stale</span>
                                    @endif
                                </small>
                            </span>
                            <span class="progress-ring" style="--value: {{ $game->completion_percent }}%">{{ $game->completion_percent }}</span>
                        </button>
                    </form>
                @empty
                    <div class="empty-state">Sync your library to begin.</div>
                @endforelse
            </nav>
        </aside>

        <main class="achievement-panel">
            @if (session('status'))
                <div class="notice success">{{ session('status') }}</div>
            @endif

            @if (session('error'))
                <div class="notice error">{{ session('error') }}</div>
            @endif

            @unless ($configured)
                <div class="notice error">Add STEAM_API_KEY to .env, then run php artisan config:clear.</div>
            @endunless

            @if ($mode === 'overview')
                <section class="overview-hero">
                    <div>
                        <p class="eyebrow">Command centre</p>
                        <h2>Achievement Dashboard</h2>
                        <p>{{ number_format($overview['unlocked_achievements']) }} unlocked from {{ number_format($overview['total_achievements']) }} tracked achievements.</p>
                    </div>
                    <div class="overview-donut" style="--value: {{ $overview['completion'] }}%">
                        <strong>{{ $overview['completion'] }}%</strong>
                        <span>overall</span>
                    </div>
                </section>

                <section class="overview-stats">
                    <div><strong>{{ number_format($overview['games']) }}</strong><span>Huntable games</span></div>
                    <div><strong>{{ number_format($overview['completed_games']) }}</strong><span>Completed games</span></div>
                    <div><strong>{{ number_format($overview['in_progress_games']) }}</strong><span>In progress</span></div>
                    <div><strong>{{ number_format($overview['locked_achievements']) }}</strong><span>Achievements left</span></div>
                    <div><strong>{{ number_format($overview['rare_missing']) }}</strong><span>Rare missing</span></div>
                    <div><strong>{{ number_format($overview['targets']) }}</strong><span>Targets marked</span></div>
                </section>

                <section class="command-grid dashboard-command-grid">
                    <article class="command-panel">
                        <h3>Completion Roadmap</h3>
                        @forelse ($roadmapGames as $game)
                            <a class="mini-row link-row" href="{{ route('games.show', ['game' => $game, 'game_filter' => $gameFilter, 'platform_filter' => $platformFilter]) }}">
                                <strong>{{ $game->name }}</strong>
                                <span>{{ $game->achievements_total - $game->achievements_unlocked }} left</span>
                            </a>
                        @empty
                            <p>No in-progress games yet.</p>
                        @endforelse
                    </article>

                    <article class="command-panel">
                        <div class="tool-heading">
                            <h3>Tonight's Hunt</h3>
                            <form method="POST" action="{{ route('hunt-session.start') }}">
                                @csrf
                                <button class="mini-action" type="submit">Start</button>
                            </form>
                        </div>
                        @forelse ($tonightAchievements as $achievement)
                            @php
                                $huntReason = match (true) {
                                    ($achievement->huntSetting?->status ?? 'none') === 'target' => 'Target',
                                    $achievement->game?->last_played_at && $achievement->game->last_played_at->diffInDays(now()) <= 14 => 'Recently played',
                                    $achievement->game && $achievement->game->achievements_total - $achievement->game->achievements_unlocked <= 5 => 'Close completion',
                                    $achievement->global_percent !== null && (float) $achievement->global_percent <= 10 => 'Rare missing',
                                    default => 'Good next pick',
                                };
                            @endphp
                            <a class="mini-row link-row" href="{{ route('games.show', ['game' => $achievement->game, 'game_filter' => $gameFilter, 'platform_filter' => $platformFilter]) }}">
                                <strong>{{ $achievement->name }}</strong>
                                <span>{{ $huntReason }} / {{ $achievement->game->name }}</span>
                            </a>
                        @empty
                            <p>Mark targets to shape this list.</p>
                        @endforelse
                    </article>

                    <article class="command-panel">
                        <h3>Rarest Missing</h3>
                        @forelse ($rarestMissing as $achievement)
                            <a class="mini-row link-row" href="{{ route('games.show', ['game' => $achievement->game, 'game_filter' => $gameFilter, 'platform_filter' => $platformFilter]) }}">
                                <strong>{{ $achievement->name }}</strong>
                                <span>{{ rtrim(rtrim(number_format((float) $achievement->global_percent, 2), '0'), '.') }}%</span>
                            </a>
                        @empty
                            <p>No missing rarity data yet.</p>
                        @endforelse
                    </article>

                    <article class="command-panel">
                        <h3>Rarest Unlocked</h3>
                        @forelse ($rarestUnlocked as $achievement)
                            <a class="mini-row link-row" href="{{ route('games.show', ['game' => $achievement->game, 'game_filter' => $gameFilter, 'platform_filter' => $platformFilter]) }}">
                                <strong>{{ $achievement->name }}</strong>
                                <span>{{ rtrim(rtrim(number_format((float) $achievement->global_percent, 2), '0'), '.') }}%</span>
                            </a>
                        @empty
                            <p>No unlocked rarity data yet.</p>
                        @endforelse
                    </article>
                </section>

                <section class="analytics-grid">
                    <article class="analytics-panel wide">
                        <div class="tool-heading">
                            <h3>Completion Spread</h3>
                            <span class="soft-label">{{ $overview['synced_games'] }} synced games</span>
                        </div>
                        <div class="bar-chart">
                            @foreach ($overview['bands'] as $label => $count)
                                <div class="bar-row">
                                    <span>{{ $label }}</span>
                                    <div><i style="width: {{ ($count / $overview['max_band']) * 100 }}%"></i></div>
                                    <strong>{{ $count }}</strong>
                                </div>
                            @endforeach
                        </div>
                    </article>

                    <article class="analytics-panel">
                        <div class="tool-heading"><h3>Achievement Split</h3></div>
                        <div class="split-chart">
                            <div class="overview-donut small" style="--value: {{ $overview['completion'] }}%"></div>
                            <div>
                                <p><strong>{{ number_format($overview['unlocked_achievements']) }}</strong> unlocked</p>
                                <p><strong>{{ number_format($overview['locked_achievements']) }}</strong> locked</p>
                                <p><strong>{{ number_format($overview['secret_locked']) }}</strong> secret locked</p>
                            </div>
                        </div>
                    </article>

                    <article class="analytics-panel">
                        <div class="tool-heading"><h3>Refresh Status</h3><span class="soft-label">{{ $refreshStatus['ran_at'] ? $refreshStatus['ran_at']->diffForHumans() : 'Not run yet' }}</span></div>
                        <div class="refresh-status-grid">
                            <div><strong>{{ $refreshStatus['checked'] }}</strong><span>Checked</span></div>
                            <div><strong>{{ $refreshStatus['synced'] }}</strong><span>Updated</span></div>
                            <div><strong>{{ $refreshStatus['failed'] }}</strong><span>Failed</span></div>
                        </div>
                        <p>{{ $refreshStatus['label'] }}</p>
                    </article>

                    <article class="analytics-panel">
                        <div class="tool-heading"><h3>Friend Activity</h3><span class="soft-label">Last 24 hours</span></div>
                        @forelse ($friendActivity as $achievement)
                            <div class="mini-row">
                                <strong>{{ $achievement->game->user?->name }} unlocked {{ $achievement->name }}</strong>
                                <span>{{ $achievement->game->name }}</span>
                            </div>
                        @empty
                            <p>No tracker friend unlocks today.</p>
                        @endforelse
                    </article>

                    <article class="analytics-panel">
                        <div class="tool-heading"><h3>Stale Data</h3></div>
                        @forelse ($staleGames as $game)
                            <a class="mini-row link-row" href="{{ route('games.show', ['game' => $game, 'game_filter' => $gameFilter, 'platform_filter' => $platformFilter]) }}">
                                <strong>{{ $game->name }}</strong>
                                <span>{{ $game->achievements_synced_at?->diffForHumans() ?? 'Never' }}</span>
                            </a>
                        @empty
                            <p>All tracked games refreshed recently.</p>
                        @endforelse
                    </article>

                    <article class="analytics-panel">
                        <div class="tool-heading"><h3>Recently Played</h3></div>
                        @forelse ($overview['recently_played'] as $game)
                            <a class="mini-row link-row" href="{{ route('games.show', ['game' => $game, 'game_filter' => $gameFilter, 'platform_filter' => $platformFilter]) }}">
                                <strong>{{ $game->name }}</strong>
                                <span>{{ $game->last_played_label }}</span>
                            </a>
                        @empty
                            <p>No last-played dates from Steam yet.</p>
                        @endforelse
                    </article>

                    <article class="analytics-panel wide">
                        <div class="tool-heading"><h3>Recent Achievements</h3><span class="soft-label">Last 24 hours</span></div>
                        <div class="recent-achievements">
                            @foreach ($recentAchievements as $achievement)
                                <a class="recent-achievement" href="{{ route('games.show', ['game' => $achievement->game, 'game_filter' => $gameFilter, 'platform_filter' => $platformFilter]) }}">
                                    <span class="achievement-icon small" data-fallback="{{ strtoupper(substr($achievement->name, 0, 2)) }}">
                                        @if ($achievement->display_icon)
                                            <img src="{{ $achievement->display_icon }}" alt="" onerror="this.parentElement.classList.add('image-missing'); this.remove()">
                                        @else
                                            <span>{{ strtoupper(substr($achievement->name, 0, 2)) }}</span>
                                        @endif
                                    </span>
                                    <span>
                                        <strong>{{ $achievement->name }}</strong>
                                        <small>{{ $achievement->game->name }} / {{ date('H:i', $achievement->unlock_time) }}</small>
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </article>
                </section>
            @elseif ($currentGame)
                <section class="current-game">
                    <div class="current-art">
                        @if ($currentGame->icon_url)
                            <img src="{{ $currentGame->icon_url }}" alt="">
                        @endif
                    </div>
                    <div class="current-copy">
                        <p class="eyebrow">{{ $currentGame->platform_label }} game</p>
                        <h2>{{ $currentGame->name }}</h2>
                        <div class="meta-row">
                            <span class="platform-badge {{ $currentGame->platform_class }}"><i></i>{{ $currentGame->platform_label }}</span>
                            <span>{{ $currentGame->achievements_unlocked }} of {{ $currentGame->achievements_total }} unlocked</span>
                            <span>{{ $currentGame->playtime_hours }} hours played</span>
                            @if ($currentGame->last_played_at)
                                <span>Last played {{ $currentGame->last_played_label }}</span>
                            @endif
                            @if ($currentGame->achievements_synced_at)
                                <span>Updated {{ $currentGame->achievements_synced_at->diffForHumans() }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="current-actions">
                        <a href="{{ $currentGame->steam_url }}" target="_blank" rel="noreferrer">{{ $currentGame->platform_label }} Store</a>
                        <a href="{{ $currentGame->achievements_url }}" target="_blank" rel="noreferrer">{{ $currentGame->platform_label }} Achievements</a>
                        <a href="{{ $currentGame->guides_url }}" target="_blank" rel="noreferrer">Guides</a>
                        @if ($currentGame->platform_key === \App\Models\SteamGame::PLATFORM_STEAM)
                            <a href="#friend-compare">Compare</a>
                        @endif
                        <form method="POST" action="{{ route('games.refresh', $currentGame) }}">
                            @csrf
                            <button type="submit" class="refresh-button">Refresh</button>
                        </form>
                    </div>
                </section>

                <section class="stats-strip">
                    <div><strong>{{ $currentGame->completion_percent }}%</strong><span>Complete</span></div>
                    <div><strong>{{ $achievements->where('hidden', true)->count() }}</strong><span>Secret shown</span></div>
                    <div><strong>{{ $achievements->filter(fn ($achievement) => $achievement->global_percent && $achievement->global_percent <= 10)->count() }}</strong><span>Rare in view</span></div>
                </section>

                <section class="hunt-tools">
                    <article class="hunt-card game-notes">
                        <div class="tool-heading">
                            <h3>Game Notes</h3>
                            @if ($currentGame->huntSetting?->archived)
                                <span class="completed-badge">Archived</span>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('games.hunt', $currentGame) }}">
                            @csrf
                            <textarea name="note" rows="3" placeholder="Private notes, missables, co-op plans">{{ old('note', $currentGame->huntSetting?->note) }}</textarea>
                            <div class="tool-grid">
                                <input name="tags" value="{{ old('tags', $currentGame->huntSetting?->tags) }}" placeholder="Tags: DLC, co-op, grind">
                                <select name="difficulty">
                                    <option value="">No difficulty</option>
                                    @foreach (['easy' => 'Easy', 'normal' => 'Normal', 'hard' => 'Hard', 'grind' => 'Grind', 'buggy' => 'Buggy', 'multiplayer' => 'Multiplayer', 'missable' => 'Missable'] as $key => $label)
                                        <option value="{{ $key }}" @selected(old('difficulty', $currentGame->huntSetting?->difficulty) === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <label class="archive-check">
                                <input type="checkbox" name="archived" value="1" @checked($currentGame->huntSetting?->archived)>
                                <span>Archive this game</span>
                            </label>
                            <button type="submit">Save Game Plan</button>
                        </form>
                    </article>

                    <article class="hunt-card">
                        <div class="tool-heading"><h3>Progress History</h3></div>
                        <div class="history-list">
                            @forelse ($history as $snapshot)
                                <div>
                                    <strong>{{ $snapshot->completion_percent }}%</strong>
                                    <span>{{ $snapshot->achievements_unlocked }}/{{ $snapshot->achievements_total }}</span>
                                    <small>{{ $snapshot->taken_at->format('M j, Y') }}</small>
                                </div>
                            @empty
                                <p>No snapshots yet. Refresh this game after the migration has run.</p>
                            @endforelse
                        </div>
                    </article>
                </section>

                @if ($currentGame->platform_key === \App\Models\SteamGame::PLATFORM_STEAM)
                <section class="compare-panel" id="friend-compare">
                    <div class="tool-heading">
                        <h3>Compare With A Tracker Friend</h3>
                        @if ($compareProfile)
                            <span class="soft-label">{{ $compareProfile->name }}</span>
                        @endif
                    </div>
                    <form method="GET" action="{{ route('games.show', $currentGame) }}" class="compare-form">
                        <input type="hidden" name="game_filter" value="{{ $gameFilter }}">
                        <input type="hidden" name="platform_filter" value="{{ $platformFilter }}">
                        <select name="friend_steam_id">
                            <option value="">Choose tracker friend</option>
                            @foreach ($friends as $friend)
                                <option value="{{ $friend->steam_id }}" @selected($compareSteamId === $friend->steam_id)>{{ $friend->name }}</option>
                            @endforeach
                        </select>
                        <button type="submit">Compare</button>
                    </form>
                    @if ($comparison->isNotEmpty())
                        @if ($compareStats)
                            <div class="compare-summary">
                                <div><strong>{{ $compareStats['you'] }}</strong><span>You</span></div>
                                <div><strong>{{ $compareStats['friend'] }}</strong><span>{{ $compareProfile->name }}</span></div>
                                <div><strong>{{ $compareStats['both_missing'] }}</strong><span>Both missing</span></div>
                                <div><strong>{{ $compareStats['only_you'] }}</strong><span>Only you</span></div>
                                <div><strong>{{ $compareStats['only_friend'] }}</strong><span>Only friend</span></div>
                            </div>
                        @endif
                        <div class="compare-grid">
                            @foreach ($comparison->take(12) as $row)
                                <div>
                                    <strong>{{ $row['name'] }}</strong>
                                    <span class="{{ $row['you'] ? 'yes' : 'no' }}">You {{ $row['you'] ? 'have' : 'need' }}</span>
                                    <span class="{{ $row['friend'] ? 'yes' : 'no' }}">Friend {{ $row['friend'] ? 'has' : 'needs' }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
                @endif

                <div class="filters">
                    @foreach (['all' => 'All', 'locked' => 'Locked', 'unlocked' => 'Unlocked', 'secret' => 'Secret', 'rare' => 'Rare'] as $key => $label)
                        <a class="{{ $filter === $key ? 'active' : '' }}" href="{{ route('games.show', ['game' => $currentGame, 'filter' => $key, 'game_filter' => $gameFilter, 'platform_filter' => $platformFilter]) }}">{{ $label }}</a>
                    @endforeach
                </div>

                <section class="achievement-grid">
                    @forelse ($achievements as $achievement)
                        @php
                            $masked = $spoilerSafe && $achievement->hidden && ! $achievement->achieved;
                            $formId = "achievement-plan-{$achievement->id}";
                            $tagSource = strtolower(($achievement->name ?? '').' '.($achievement->description ?? '').' '.($achievement->huntSetting?->tags ?? ''));
                            $achievementTags = collect([
                                str_contains($tagSource, 'dlc') || str_contains($tagSource, 'episode') || str_contains($tagSource, 'chapter') ? 'DLC?' : null,
                                str_contains($tagSource, 'missable') ? 'Missable' : null,
                                str_contains($tagSource, 'multiplayer') || str_contains($tagSource, 'co-op') || str_contains($tagSource, 'coop') ? 'Multiplayer' : null,
                                $achievement->huntSetting?->difficulty ? ucfirst($achievement->huntSetting->difficulty) : null,
                            ])->filter();
                        @endphp
                        <article class="achievement-card {{ $achievement->achieved ? 'unlocked' : 'locked' }} {{ $achievement->rarity_class }}">
                            <div class="achievement-icon" data-fallback="{{ strtoupper(substr($achievement->name, 0, 2)) }}">
                                @if ($achievement->display_icon)
                                    <img src="{{ $achievement->display_icon }}" alt="" onerror="this.parentElement.classList.add('image-missing'); this.remove()">
                                @else
                                    <span>{{ strtoupper(substr($achievement->name, 0, 2)) }}</span>
                                @endif
                            </div>
                            <div class="achievement-copy">
                                <div class="achievement-title-row">
                                    <h3>{{ $masked ? 'Secret achievement' : $achievement->name }}</h3>
                                    @if ($achievement->hidden)
                                        <span class="secret-pill">Secret</span>
                                    @endif
                                    @foreach ($achievementTags as $tag)
                                        <span class="secret-pill info-pill">{{ $tag }}</span>
                                    @endforeach
                                </div>
                                <p>{{ $masked ? 'Spoiler hidden. Toggle spoiler-safe mode to reveal this one.' : ($achievement->description ?: 'No description supplied by Steam.') }}</p>
                                <div class="achievement-meta">
                                    @if ($achievement->global_percent)
                                        <span>{{ rtrim(rtrim(number_format((float) $achievement->global_percent, 2), '0'), '.') }}% of players</span>
                                    @else
                                        <span>Rarity unknown</span>
                                    @endif
                                    @if ($achievement->achieved && $achievement->unlock_time)
                                        <span>{{ date('M j, Y', $achievement->unlock_time) }}</span>
                                    @else
                                        <span>Not unlocked</span>
                                    @endif
                                </div>
                                @if ($achievement->has_progress)
                                    <div class="achievement-progress" style="--value: {{ $achievement->progress_percent }}%">
                                        <div><span></span></div>
                                        <input
                                            type="range"
                                            name="manual_progress_current"
                                            min="0"
                                            max="{{ $achievement->progress_target_value }}"
                                            value="{{ $achievement->progress_current_value }}"
                                            form="{{ $formId }}"
                                            data-progress-slider
                                            aria-label="Achievement progress"
                                        >
                                        <strong data-progress-label>{{ number_format($achievement->progress_current_value) }} / {{ number_format($achievement->progress_target_value) }}</strong>
                                    </div>
                                @endif
                                <form id="{{ $formId }}" method="POST" action="{{ route('achievements.hunt', $achievement) }}" class="achievement-plan">
                                    @csrf
                                    <select name="status">
                                        @foreach (['none' => 'No plan', 'target' => 'Target', 'later' => 'Later', 'ignore' => 'Ignore'] as $key => $label)
                                            <option value="{{ $key }}" @selected(($achievement->huntSetting?->status ?? 'none') === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <select name="difficulty">
                                        <option value="">Difficulty</option>
                                        @foreach (['easy' => 'Easy', 'normal' => 'Normal', 'hard' => 'Hard', 'grind' => 'Grind', 'buggy' => 'Buggy', 'multiplayer' => 'Multiplayer', 'missable' => 'Missable'] as $key => $label)
                                            <option value="{{ $key }}" @selected(($achievement->huntSetting?->difficulty ?? '') === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <input name="tags" value="{{ $achievement->huntSetting?->tags }}" placeholder="Tags">
                                    <input name="note" value="{{ $achievement->huntSetting?->note }}" placeholder="Note">
                                    <input type="hidden" name="manual_progress_target" value="{{ $achievement->progress_target_value }}">
                                    <button type="submit">Save</button>
                                </form>
                            </div>
                        </article>
                    @empty
                        <div class="empty-achievements">
                            <h3>No achievements loaded</h3>
                            <p>Refresh this game to pull achievement names, secret descriptions, unlock state, and rarity.</p>
                        </div>
                    @endforelse
                </section>
            @else
                <section class="empty-dashboard">
                    <h2>No games yet</h2>
                    <p>Sync Steam or link PSN to start tracking achievements.</p>
                </section>
            @endif
        </main>
    </div>

    <div class="sync-modal" data-sync-modal hidden>
        <section class="sync-modal-panel" role="dialog" aria-modal="true" aria-labelledby="sync-modal-title">
            <div class="sync-loader" aria-hidden="true"></div>
            <p class="eyebrow">Steam sync</p>
            <h2 id="sync-modal-title">Syncing achievements</h2>
            <p data-sync-status>Preparing library check...</p>
            <div class="sync-progress" aria-hidden="true">
                <span data-sync-progress style="width: 0%"></span>
            </div>
            <small data-sync-detail>Keeping the page open until Steam finishes.</small>
            <button type="button" data-sync-dismiss hidden>Refresh Page</button>
        </section>
    </div>
</body>
</html>
