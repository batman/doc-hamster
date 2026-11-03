// Side menu, receipt upload, dropdowns and the receipt filter (layouts/app).
// Loaded in <head> so the saved menu state applies before the first paint;
// the rest waits for the DOM.
(() => {
    const root = document.documentElement;
    const KEY = 'dh-nav';
    const read = () => { try { return localStorage.getItem(KEY); } catch { return null; } };
    const save = value => { try { localStorage.setItem(KEY, value); } catch { /* private mode: not remembered */ } };

    const saved = read();
    if (saved === 'closed' || saved === 'rail') {
        root.classList.add(`nav-${saved}`);
    }

    const setupMenu = () => {
        const nav = document.getElementById('nav');
        if (!nav) {
            return;
        }
        const toggle = document.querySelector('.nav-toggle');
        const collapse = nav.querySelector('.nav-collapse');
        // Same breakpoint as app.css: below it the menu is a drawer over the page.
        const mobile = matchMedia('(max-width: 63.99rem)');

        const sync = () => {
            const open = mobile.matches ? root.classList.contains('nav-open') : !root.classList.contains('nav-closed');
            toggle.setAttribute('aria-expanded', String(open));
            collapse.setAttribute('aria-label', root.classList.contains('nav-rail') ? collapse.dataset.expand : collapse.dataset.collapse);
        };
        const store = () => save(root.classList.contains('nav-closed') ? 'closed' : (root.classList.contains('nav-rail') ? 'rail' : 'open'));
        const closeDrawer = () => {
            root.classList.remove('nav-open');
            sync();
        };

        toggle.addEventListener('click', () => {
            if (mobile.matches) {
                root.classList.toggle('nav-open');
            } else {
                root.classList.toggle('nav-closed');
                store();
            }
            sync();
        });
        collapse.addEventListener('click', () => {
            root.classList.toggle('nav-rail');
            store();
            sync();
        });
        document.querySelector('.nav-backdrop').addEventListener('click', closeDrawer);
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && root.classList.contains('nav-open')) {
                closeDrawer();
                toggle.focus();
            }
        });
        mobile.addEventListener('change', closeDrawer);
        sync();

        // Upload starts right after choosing; files dropped anywhere on the page go the same way.
        const form = nav.querySelector('.nav-upload');
        if (!form) {
            return;
        }
        const input = form.querySelector('input[type="file"]');
        const overlay = document.querySelector('.drop-overlay');
        const send = () => {
            if (input.files.length === 0) {
                return;
            }
            form.querySelector('.nav-text').textContent = form.dataset.busy;
            form.classList.add('is-busy');
            overlay.querySelector('p').textContent = form.dataset.busy;
            overlay.hidden = false;
            form.submit();
        };
        input.addEventListener('change', send);

        const carriesFiles = event => event.dataTransfer !== null && [...event.dataTransfer.types].includes('Files');
        let depth = 0; // dragenter/dragleave fire for every child element
        document.addEventListener('dragenter', event => {
            if (carriesFiles(event)) {
                depth++;
                overlay.hidden = false;
            }
        });
        document.addEventListener('dragleave', event => {
            if (carriesFiles(event) && --depth <= 0) {
                depth = 0;
                overlay.hidden = true;
            }
        });
        document.addEventListener('dragover', event => {
            if (carriesFiles(event)) {
                event.preventDefault();
            }
        });
        document.addEventListener('drop', event => {
            if (!carriesFiles(event)) {
                return;
            }
            event.preventDefault();
            depth = 0;
            input.files = event.dataTransfer.files;
            send();
        });
    };

    // Toolbar dropdowns (filter, view): the icon button opens its panel; Escape and a click outside close it.
    const setupDropdowns = () => {
        const dropdowns = [...document.querySelectorAll('.dropdown')];
        const setOpen = (dropdown, open) => {
            dropdown.querySelector('.dropdown-panel').hidden = !open;
            dropdown.querySelector('.icon-button').setAttribute('aria-expanded', String(open));
        };
        const openOne = () => dropdowns.find(dropdown => !dropdown.querySelector('.dropdown-panel').hidden);

        dropdowns.forEach(dropdown => dropdown.querySelector('.icon-button').addEventListener('click', () => {
            const open = dropdown.querySelector('.dropdown-panel').hidden;
            dropdowns.forEach(other => setOpen(other, false));
            setOpen(dropdown, open);
        }));
        document.addEventListener('click', event => {
            if (!event.target.closest('.dropdown')) {
                dropdowns.forEach(dropdown => setOpen(dropdown, false));
            }
        });
        document.addEventListener('keydown', event => {
            const open = openOne();
            if (event.key === 'Escape' && open) {
                setOpen(open, false);
                open.querySelector('.icon-button').focus();
            }
        });
    };

    // Receipt filter: applies with every tick. A receipt must have all ticked tags.
    // When the page doesn't hold every receipt, the server filters instead.
    const setupFilter = () => {
        const filter = document.querySelector('.dropdown[data-kind="filter"]');
        if (!filter) {
            return;
        }
        const boxes = [...filter.querySelectorAll('input[name="tags[]"]')];
        const reset = filter.querySelector('[data-reset]');

        const apply = () => {
            if (filter.dataset.complete !== '1') {
                filter.submit();
                return;
            }
            const wanted = boxes.filter(box => box.checked).map(box => box.value);
            let shown = 0;
            document.querySelectorAll('[data-item]').forEach(item => {
                const has = item.dataset.tags.split(' ');
                item.hidden = !wanted.every(tag => has.includes(tag));
                shown += item.hidden ? 0 : 1;
            });
            document.querySelectorAll('[data-group]').forEach(group => {
                group.hidden = group.querySelector('[data-item]:not([hidden])') === null;
            });
            const none = document.querySelector('[data-no-match]');
            if (none) {
                none.hidden = shown > 0 || wanted.length === 0;
            }

            const active = wanted.length > 0;
            filter.querySelector('.icon-button').classList.toggle('is-active', active);
            filter.querySelector('[data-icon="off"]').hidden = active;
            filter.querySelector('[data-icon="on"]').hidden = !active;
            const badge = filter.querySelector('.badge');
            badge.textContent = String(wanted.length);
            badge.hidden = !active;
            reset.hidden = !active;

            // Keep the address in step, so reloading or a shared link shows the same.
            const url = new URL(location.href);
            url.searchParams.delete('tags[]');
            wanted.forEach(tag => url.searchParams.append('tags[]', tag));
            history.replaceState(null, '', url);
        };

        boxes.forEach(box => box.addEventListener('change', apply));
        reset.addEventListener('click', () => {
            boxes.forEach(box => { box.checked = false; });
            apply();
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        setupMenu();
        setupDropdowns();
        setupFilter();
    });
})();
