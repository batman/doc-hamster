// Menu window ("Menü-Fenster", point 156, A331; docs/design/FRAMEWORK.md): the switch in the toolbar shows the entries
// as a list or as tiles. Only this window changes; the choice is remembered per menu window key in the cookie
// dh_menu_views ("key:tiles|key:list"), which the server reads (App\Http\MenuViews), so the next load draws it right away.
// Delegated, so menu windows loaded later work too.
(() => {
    const COOKIE = 'dh_menu_views';
    const MAX = 2000;
    const WIDTHS = { tiles: ['16rem', '36rem', '56rem'], list: ['12rem', '22rem', '28rem'] };

    const read = () => {
        try {
            const hit = document.cookie.match(/(?:^|;\s*)dh_menu_views=([^;]*)/);
            return hit ? decodeURIComponent(hit[1]).split('|').filter(Boolean) : [];
        } catch {
            return [];
        }
    };
    const remember = (key, display) => {
        try {
            const list = read().filter(entry => entry.slice(0, entry.lastIndexOf(':')) !== key);
            list.push(`${key}:${display}`);
            while (list.length > 1 && list.join('|').length > MAX) list.shift();
            document.cookie = `${COOKIE}=${encodeURIComponent(list.join('|'))}; path=/; max-age=31536000; SameSite=Lax`;
        } catch { /* only a convenience */ }
    };

    document.addEventListener('click', event => {
        const button = event.target.closest?.('[data-menu-display]');
        const win = button?.closest('section[data-window]');
        // A68: a menu window with foldable groups has one list per group; all switch together.
        const lists = [...(win?.querySelectorAll('[data-menu-entries]') ?? [])];
        const entries = lists[0];
        if (!entries) return;
        const display = button.dataset.menuDisplay;
        lists.forEach(list => {
            list.classList.remove('is-list', 'is-tiles');
            list.classList.add(`is-${display}`);
        });
        button.parentElement.querySelectorAll('[data-menu-display]').forEach(other => {
            other.setAttribute('aria-pressed', String(other === button));
        });
        // The same widths as components/menu-window.blade.php: tiles want room, a list is narrow.
        const [min, opt, max] = WIDTHS[display] ?? WIDTHS.list;
        Object.assign(win.dataset, { windowMin: min, windowOpt: opt, windowMax: max });
        win.style.setProperty('--win-min', min);
        remember(entries.dataset.menuEntries, display);
        document.dispatchEvent(new Event('dh:win-layout'));
    });
})();
