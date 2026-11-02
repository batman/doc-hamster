// Side menu and receipt upload (layouts/app). Loaded in <head> so the saved
// menu state applies before the first paint; the rest waits for the DOM.
(() => {
    const root = document.documentElement;
    const KEY = 'dh-nav';
    const read = () => { try { return localStorage.getItem(KEY); } catch { return null; } };
    const save = value => { try { localStorage.setItem(KEY, value); } catch { /* private mode: not remembered */ } };

    const saved = read();
    if (saved === 'closed' || saved === 'rail') {
        root.classList.add(`nav-${saved}`);
    }

    document.addEventListener('DOMContentLoaded', () => {
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
        // The hamster in the overlay: awake while files hover, eating the carrot while they upload.
        let hamster = null;
        const showOverlay = mood => {
            overlay.hidden = false;
            const box = overlay.querySelector('.drop-hamster');
            if (!box || !window.docHamster) {
                return;
            }
            if (hamster === null) {
                hamster = window.docHamster.mount(box, { mood });
            } else {
                hamster.mood(mood);
            }
        };
        const hideOverlay = () => {
            overlay.hidden = true;
            if (hamster !== null) {
                hamster.stop();
            }
        };
        const send = () => {
            if (input.files.length === 0) {
                return;
            }
            form.querySelector('.nav-text').textContent = form.dataset.busy;
            form.classList.add('is-busy');
            overlay.querySelector('p').textContent = form.dataset.busy;
            showOverlay('eat');
            form.submit();
        };
        input.addEventListener('change', send);

        const carriesFiles = event => event.dataTransfer !== null && [...event.dataTransfer.types].includes('Files');
        let depth = 0; // dragenter/dragleave fire for every child element
        document.addEventListener('dragenter', event => {
            if (carriesFiles(event)) {
                depth++;
                if (overlay.hidden) {
                    showOverlay('awake');
                }
            }
        });
        document.addEventListener('dragleave', event => {
            if (carriesFiles(event) && --depth <= 0) {
                depth = 0;
                hideOverlay();
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
    });
})();
