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

            <div class="game-filters" aria-label="Game filters">
                @foreach ([
                    'all' => 'All',
                    'in_progress' => 'In progress',
                    'completed' => 'Completed',
                    'unchecked' => 'Unchecked',
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
                    <form method="POST" action="{{ route('games.refresh', $currentGame) }}">
                        @csrf
                        <button type="submit" class="refresh-button">Refresh</button>
                    </form>
                </section>

                <section class="stats-strip">
                    <div><strong>{{ $currentGame->completion_percent }}%</strong><span>Complete</span></div>
                    <div><strong>{{ $achievements->where('hidden', true)->count() }}</strong><span>Secret shown</span></div>
                    <div><strong>{{ $achievements->filter(fn ($achievement) => $achievement->global_percent && $achievement->global_percent <= 10)->count() }}</strong><span>Rare in view</span></div>
                </section>

                <div class="filters">
                    @foreach (['all' => 'All', 'locked' => 'Locked', 'unlocked' => 'Unlocked', 'secret' => 'Secret', 'rare' => 'Rare'] as $key => $label)
                        <a class="{{ $filter === $key ? 'active' : '' }}" href="{{ route('dashboard', ['filter' => $key, 'game_filter' => $gameFilter]) }}">{{ $label }}</a>
                    @endforeach
                </div>

                <section class="achievement-grid">
                    @forelse ($achievements as $achievement)
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
                                    <h3>{{ $achievement->name }}</h3>
                                    @if ($achievement->hidden)
                                        <span class="secret-pill">Secret</span>
                                    @endif
                                </div>
                                <p>{{ $achievement->description ?: 'No description supplied by Steam.' }}</p>
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
