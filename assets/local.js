(() => {
    'use strict';
    document.querySelectorAll('[data-yseo-store-locator]').forEach((locator) => {
        const input = locator.querySelector('[data-yseo-location-search]');
        const cards = Array.from(locator.querySelectorAll('[data-yseo-location-card]'));
        const empty = locator.querySelector('[data-yseo-location-empty]');
        if (!input) { return; }
        const update = () => {
            const query = input.value.trim().toLocaleLowerCase();
            let visible = 0;
            cards.forEach((card) => {
                const haystack = (card.getAttribute('data-yseo-location-text') || '').toLocaleLowerCase();
                const show = !query || haystack.includes(query);
                card.hidden = !show;
                if (show) { visible += 1; }
            });
            if (empty) { empty.hidden = visible > 0; }
        };
        input.addEventListener('input', update);
    });
})();
