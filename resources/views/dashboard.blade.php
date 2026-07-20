<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Achievement Tracker</title>
    <link rel="stylesheet" href="{{ asset('assets/tracker.css') }}">
    <script src="{{ asset('assets/tracker.js') }}" defer></script>
</head>
<body class="tracker-body">
    <div class="tracker-shell">
        <aside class="games-panel">
            <div class="panel-top">
                <div>
                    <p class="eyebrow">Steam library</p>
                    <h1>Achievements</h1>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="icon-button" type="submit" title="Lock tracker" aria-label="Lock tracker">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10V8a6 6 0 1 1 12 0v2h1a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1h1Zm2 0h8V8a4 4 0 0 0-8 0v2Z"/></svg>
                    </button>
                </form>
            </div>

            <form method="POST" action="{{ route('sync.library') }}" class="sync-row">
                @csrf
                <button type="submit">Sync Library</button>
                <span>{{ $games->count() }} huntable</span>
            </form>

            <form method="POST" action="{{ route('sync.achievements') }}" class="sync-row compact">
                @csrf
                <button type="submit">Sync Achievements</button>
                <span>{{ $unsyncedGames }} left</span>
            </form>

            <form method="POST" action="{{ route('spoilers.update') }}" class="spoiler-toggle">
                @csrf
                <label>
                    <input type="checkbox" name="spoiler_safe" value="1" @checked($spoilerSafe) onchange="this.form.submit()">
                    <span>Spoiler-safe secrets</span>
                </label>
            </form>

            <div class="game-filters" aria-label="Game filters">
                @foreach ([
                    'all' => 'All',
                    'in_progress' => 'In progress',
                    'completed' => 'Completed',
                    'unchecked' => 'Unchecked',
                    'archived' => 'Archived',
                ] as $key => $label)
                    <a class="{{ $gameFilter === $key ? 'active' : '' }}" href="{{ route('dashboard', ['game_filter' => $key]) }}">
                        <span>{{ $label }}</span>
                        <strong>{{ $gameCounts[$key] }}</strong>
                    </a>
                @endforeach
            </div>

            <input class="game-search" type="search" placeholder="Search games" data-game-search>

            <nav class="game-list" aria-label="Games">
                @forelse ($games as $game)
                    <form method="POST" action="{{ route('games.current', $game) }}" class="game-tile {{ $game->is_current ? 'active' : '' }} {{ $game->is_completed ? 'completed' : '' }}" data-game-name="{{ strtolower($game->name) }}">
                        @csrf
                        <input type="hidden" name="game_filter" value="{{ $gameFilter }}">
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
                <div class="notice error">Add STEAM_API_KEY and STEAM_ID to .env, then run php artisan config:clear.</div>
            @endunless

            @if ($currentGame)
                <section class="current-game">
                    <div class="current-art">
                        @if ($currentGame->icon_url)
                            <img src="{{ $currentGame->icon_url }}" alt="">
                        @endif
                    </div>
                    <div class="current-copy">
                        <p class="eyebrow">Current game</p>
                        <h2>{{ $currentGame->name }}</h2>
                        <div class="meta-row">
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
                        <a href="{{ $currentGame->steam_url }}" target="_blank" rel="noreferrer">Store</a>
                        <a href="{{ $currentGame->achievements_url }}" target="_blank" rel="noreferrer">Steam Achievements</a>
                        <a href="{{ $currentGame->guides_url }}" target="_blank" rel="noreferrer">Guides</a>
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

                <section class="command-grid">
                    <article class="command-panel">
                        <h3>Completion Roadmap</h3>
                        @forelse ($roadmapGames as $game)
                            <div class="mini-row">
                                <strong>{{ $game->name }}</strong>
                                <span>{{ $game->achievements_total - $game->achievements_unlocked }} left</span>
                            </div>
                        @empty
                            <p>No in-progress games with achievement data yet.</p>
                        @endforelse
                    </article>

                    <article class="command-panel">
                        <h3>Tonight's Hunt</h3>
                        @forelse ($tonightAchievements as $achievement)
                            <div class="mini-row">
                                <strong>{{ $achievement->name }}</strong>
                                <span>{{ $achievement->game->name }}</span>
                            </div>
                        @empty
                            <p>Mark targets or sync more achievements.</p>
                        @endforelse
                    </article>

                    <article class="command-panel">
                        <h3>Rarest Missing</h3>
                        @forelse ($rarestMissing as $achievement)
                            <div class="mini-row">
                                <strong>{{ $achievement->name }}</strong>
                                <span>{{ rtrim(rtrim(number_format((float) $achievement->global_percent, 2), '0'), '.') }}%</span>
                            </div>
                        @empty
                            <p>No missing rarity data yet.</p>
                        @endforelse
                    </article>

                    <article class="command-panel">
                        <h3>Rarest Unlocked</h3>
                        @forelse ($rarestUnlocked as $achievement)
                            <div class="mini-row">
                                <strong>{{ $achievement->name }}</strong>
                                <span>{{ rtrim(rtrim(number_format((float) $achievement->global_percent, 2), '0'), '.') }}%</span>
                            </div>
                        @empty
                            <p>No unlocked rarity data yet.</p>
                        @endforelse
                    </article>

                    <article class="command-panel wide">
                        <h3>Achievement Planner</h3>
                        @forelse ($plannedAchievements as $achievement)
                            <div class="mini-row">
                                <strong>{{ $achievement->name }}</strong>
                                <span>{{ ucfirst($achievement->huntSetting?->status ?? 'target') }} · {{ $achievement->game->name }}</span>
                            </div>
                        @empty
                            <p>Use the planner controls on achievements to build this list.</p>
                        @endforelse
                    </article>
                </section>

                <div class="filters">
                    @foreach (['all' => 'All', 'locked' => 'Locked', 'unlocked' => 'Unlocked', 'secret' => 'Secret', 'rare' => 'Rare'] as $key => $label)
                        <a class="{{ $filter === $key ? 'active' : '' }}" href="{{ route('dashboard', ['filter' => $key, 'game_filter' => $gameFilter]) }}">{{ $label }}</a>
                    @endforeach
                </div>

                <section class="achievement-grid">
                    @forelse ($achievements as $achievement)
                        @php
                            $masked = $spoilerSafe && $achievement->hidden && ! $achievement->achieved;
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
                                <form method="POST" action="{{ route('achievements.hunt', $achievement) }}" class="achievement-plan">
                                    @csrf
                                    <select name="status">
                                        @foreach (['none' => 'No plan', 'target' => 'Target', 'later' => 'Later', 'ignore' => 'Ignore'] as $key => $label)
                                            <option value="{{ $key }}" @selected(($achievement->huntSetting?->status ?? 'none') === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <input name="tags" value="{{ $achievement->huntSetting?->tags }}" placeholder="Tags">
                                    <input name="note" value="{{ $achievement->huntSetting?->note }}" placeholder="Note">
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
                    <h2>No Steam games yet</h2>
                    <p>Add your Steam credentials, then sync your library.</p>
                </section>
            @endif
        </main>
    </div>
</body>
</html>
