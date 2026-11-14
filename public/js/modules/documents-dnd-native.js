/* Documents and bookings dragged with the mouse: the browser's own drag and drop (point 160, A336-A340).
   It reaches every window of this page, other DocHamster tabs and windows of the same browser, and the desktop:
   - application/x-dochamster-document  JSON {org, ids, trashed} – documents (all picked rows, A338);
   - application/x-dochamster-booking   JSON {org, url} – a booking row, its link address (A337);
   - application/x-dochamster-org-<id>  the organisation as a type of its own, because dragover may read the types but
     not the data: a tab of another organisation shows "no drop" at once (the server checks again);
   - text/uri-list and text/plain       the document or booking addresses (other apps, the address bar);
   - DownloadURL                        "mime:name:url" of one document: dropped on the desktop/Explorer it is the file
     (Chrome, A340).
   Where a drop lands and what it does come from documents-dnd.js (aim, land), so it feels like the pointer engine:
   same orange label (the drag image), same highlights, Escape cancels. A place that takes nothing shows "no drop". */
(() => {
    const DocHamster = window.DocHamster;
    const docs = DocHamster?.docs;
    if (!docs?.aim) {
        return;
    }
    const { config, aim, land, carryOf, ghostOf, showTargets, edgeScroll, sourceAt, setTarget, refresh } = docs;
    const DOC = 'application/x-dochamster-document';
    const BOOKING = 'application/x-dochamster-booking';
    const ORG = 'application/x-dochamster-org-';
    const TRASHED = 'application/x-dochamster-trashed';
    const org = () => (config().org ?? '').toLowerCase();
    const absolute = url => new URL(url, location.href).href;
    const docUrl = id => absolute((config().docUrl ?? '/documents/__ID__').replace('__ID__', id));

    let armed = null; // the row the mouse went down on
    let local = null; // what a drag that started on this page carries
    let droppedHere = false;

    // The mouse goes down on a document or booking: the row becomes draggable for this one gesture.
    document.addEventListener('pointerdown', event => {
        if (armed) {
            armed.draggable = false;
            armed = null;
        }
        if (event.pointerType === 'touch' || event.button !== 0) {
            return;
        }
        const source = sourceAt(event.target);
        if (source && !source.matches('[data-drag-folder]')) {
            armed = source;
            armed.draggable = true;
        }
    });

    document.addEventListener('dragstart', event => {
        const el = event.target instanceof Element ? event.target : event.target?.parentElement;
        const source = armed && el && armed.contains(el) ? armed : null;
        if (!source) {
            // Folders move with the pointer engine; links and images inside rows never start a drag of their own.
            if (el?.closest('[data-doc], [data-doc-drag], [data-booking-drag], [data-drag-folder]')) {
                event.preventDefault();
            }
            return;
        }
        const carry = carryOf(source);
        const dt = event.dataTransfer;
        dt.clearData();
        dt.setData(ORG + org(), '1');
        if (carry.kind === 'booking') {
            dt.setData(BOOKING, JSON.stringify({ org: org(), url: absolute(carry.url) }));
            const link = source.querySelector('a[href]')?.href;
            if (link) {
                dt.setData('text/uri-list', link);
                dt.setData('text/plain', link);
            }
        } else {
            dt.setData(DOC, JSON.stringify({ org: org(), ids: carry.ids, trashed: carry.trashed }));
            if (carry.trashed) {
                dt.setData(TRASHED, '1');
            }
            const urls = carry.ids.map(docUrl);
            dt.setData('text/uri-list', urls.join('\r\n'));
            dt.setData('text/plain', urls.join('\n'));
            const file = carry.items.length === 1 ? carry.items[0].dataset.docFile : '';
            if (file) {
                dt.setData('DownloadURL', file);
            }
        }
        dt.effectAllowed = 'all';
        const ghost = ghostOf(carry.label, event.clientX, event.clientY);
        dt.setDragImage(ghost, -14, -10);
        setTimeout(() => ghost.remove(), 0);
        carry.items.forEach(item => item.classList.add('is-carried'));
        local = carry;
        droppedHere = false;
        showTargets(carry);
    });

    // What the drag over this page carries: our own drag, or one from another tab (only its types are readable).
    const carried = event => {
        const types = [...(event.dataTransfer?.types ?? [])];
        const kind = types.includes(DOC) ? 'docs' : types.includes(BOOKING) ? 'booking' : null;
        if (!kind) {
            return null;
        }
        return { kind, sameOrg: org() !== '' && types.includes(ORG + org()), carry: local ?? { kind, items: [], ids: null, trashed: types.includes(TRASHED), foreign: true } };
    };
    const end = () => {
        setTarget(null);
        showTargets(local);
    };

    document.addEventListener('dragover', event => {
        const drag = carried(event);
        if (!drag) {
            return;
        }
        // Ours everywhere on the page: nothing else (a link drop) may open the address instead.
        event.preventDefault();
        if (!local) {
            showTargets(drag.sameOrg ? drag.carry : null);
        }
        const target = drag.sameOrg ? aim(drag.carry, event.clientX, event.clientY) : null;
        event.dataTransfer.dropEffect = !target ? 'none' : target.type === 'link' ? 'link' : 'move';
        setTarget(target?.el ?? null);
        edgeScroll(event.clientX, event.clientY, 3);
    });

    document.addEventListener('dragleave', event => {
        if (carried(event) && !event.relatedTarget) {
            end(); // left the page (towards another tab or the desktop)
        }
    });

    document.addEventListener('drop', event => {
        const drag = carried(event);
        if (!drag) {
            return;
        }
        event.preventDefault();
        const target = drag.sameOrg ? aim(drag.carry, event.clientX, event.clientY) : null;
        end();
        if (!target) {
            return;
        }
        let carry = local;
        if (!carry) {
            try {
                const data = JSON.parse(event.dataTransfer.getData(drag.kind === 'docs' ? DOC : BOOKING));
                if (String(data.org ?? '').toLowerCase() !== org()) {
                    return;
                }
                carry = { kind: drag.kind, items: [], ids: Array.isArray(data.ids) ? data.ids.map(String) : null, url: data.url ?? null, trashed: Boolean(data.trashed) };
            } catch {
                return;
            }
        }
        // A booking from another tab links through its own address, which must be a booking link of this app.
        if (carry.kind === 'booking') {
            const url = carry.url ? new URL(carry.url, location.href) : null;
            if (!url || url.origin !== location.origin || !/\/bank\/transactions\/[^/]+\/link$/.test(url.pathname)) {
                return;
            }
        }
        droppedHere = true;
        land(carry, carry.kind === 'booking' ? { ...target, url: carry.url } : target);
    });

    document.addEventListener('dragend', event => {
        if (!local) {
            return;
        }
        local.items.forEach(item => item.classList.remove('is-carried'));
        const moved = !droppedHere && ['move', 'link'].includes(event.dataTransfer?.dropEffect);
        local = null;
        end();
        if (armed) {
            armed.draggable = false;
            armed = null;
        }
        // Dropped in another tab: this page's windows follow (the other tab already reloaded its own).
        if (moved) {
            setTimeout(() => {
                refresh();
                DocHamster.emit('bank:changed', {});
            }, 400);
        }
    });
})();
