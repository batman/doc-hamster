/* Auswahl-Leiste (point 134, A280): a small floating toolbar above a text selection */
// DocHamster.selectionBar.show(rect, actions) shows one bar for the whole page (there is only one text selection)
// above the selection's rect (below it when there is no room), with buttons { label, icon (svg markup), run }.
// It lies in the top layer (core/top-layer.js) like the tooltip and hides on hide(), Escape, scrolling, resizing and
// when the selection goes away. The buttons never take the selection away (mousedown is prevented).
// DocHamster.copyText(text) copies to the clipboard (Promise, false when the browser refuses).
(() => {
    const DocHamster = window.DocHamster;
    const GAP = 8;
    let bar = null;
    let onHide = null;

    const hide = () => {
        if (!bar || bar.hidden) return;
        bar.hidden = true;
        bar.replaceChildren();
        const done = onHide;
        onHide = null;
        done?.();
    };
    const place = rect => {
        const w = bar.offsetWidth;
        const h = bar.offsetHeight;
        const vw = document.documentElement.clientWidth;
        const x = Math.max(GAP, Math.min(rect.left + rect.width / 2 - w / 2, vw - w - GAP));
        const y = rect.top - h - GAP >= GAP ? rect.top - h - GAP : Math.min(rect.bottom + GAP, innerHeight - h - GAP);
        Object.assign(bar.style, { left: `${Math.round(x)}px`, top: `${Math.round(y)}px` });
    };
    const show = (rect, actions, { hidden } = {}) => {
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'selection-bar';
            bar.setAttribute('role', 'toolbar');
            bar.hidden = true;
            document.body.append(bar);
        }
        hide();
        actions.forEach(action => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button-quiet button-small';
            button.innerHTML = action.icon ?? '';
            button.append(document.createTextNode(action.label));
            button.addEventListener('mousedown', event => event.preventDefault());
            button.addEventListener('click', () => {
                hide();
                action.run();
            });
            bar.append(button);
        });
        onHide = hidden ?? null;
        bar.hidden = false;
        place(rect);
    };

    const copyText = text => {
        const fallback = () => {
            const area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
            document.body.append(area);
            area.select();
            let ok = false;
            try { ok = document.execCommand('copy'); } catch { ok = false; }
            area.remove();
            return ok;
        };
        return navigator.clipboard?.writeText ? navigator.clipboard.writeText(text).then(() => true, () => fallback()) : Promise.resolve(fallback());
    };

    document.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
    document.addEventListener('scroll', hide, true);
    addEventListener('resize', hide);
    document.addEventListener('selectionchange', () => {
        if (bar && !bar.hidden && (document.getSelection()?.isCollapsed ?? true)) hide();
    });

    DocHamster.selectionBar = { show, hide };
    DocHamster.copyText = copyText;
})();
