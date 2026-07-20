const gameSearch = document.querySelector('[data-game-search]');

if (gameSearch) {
    gameSearch.addEventListener('input', (event) => {
        const term = event.target.value.trim().toLowerCase();

        document.querySelectorAll('[data-game-name]').forEach((game) => {
            game.hidden = !game.dataset.gameName.includes(term);
        });
    });
}
