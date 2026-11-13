/* Package M (point 53): dialogs, dropdown panels, toasts and the tooltip sit in the browser's top layer
   (popover="manual") while shown, so a window's overflow, container queries or stacking can't cut or cover
   them, and a dialog's backdrop greys the whole app. The element stays where it is in the DOM: forms,
   closest('section[data-window]'), per-window names and window reloads keep working. Modules keep using
   the hidden attribute; this follows it. Shown later = drawn on top. */
(() => {
    if (!HTMLElement.prototype.showPopover) {
        return;
    }
    const LAYER = '[data-dialog], .dialog-backdrop, .dropdown-panel, .tip, .toast, .selection-bar';
    const GAP = 8;
    // A dropdown panel hangs fixed below its button, right edges flush; it turns rightwards when there is no
    // room on the left and opens upwards when there is more room above than below. Long panels scroll.
    const place = panel => {
        const anchor = panel.parentElement?.querySelector(':scope > .icon-button') ?? panel.parentElement;
        if (!anchor || !panel.matches(':popover-open')) {
            return;
        }
        const a = anchor.getBoundingClientRect();
        panel.style.maxHeight = '';
        const w = panel.offsetWidth;
        const h = panel.offsetHeight;
        const vw = document.documentElement.clientWidth;
        const below = innerHeight - a.bottom - 2 * GAP;
        const above = a.top - 2 * GAP;
        const up = h > below && above > below;
        const room = Math.max(120, up ? above : below);
        let x = a.right - w;
        if (x < GAP) {
            x = a.left;
        }
        x = Math.max(GAP, Math.min(x, vw - w - GAP));
        const y = up ? Math.max(GAP, a.top - GAP - Math.min(h, room)) : a.bottom + GAP;
        Object.assign(panel.style, { left: `${Math.round(x)}px`, top: `${Math.round(y)}px`, right: 'auto', bottom: 'auto', maxHeight: `${Math.round(room)}px` });
    };
    // Removing the attribute also takes the element out of the top layer; it then shows in place again,
    // so code that focuses a field right after hidden = false still finds it visible.
    const drop = el => {
        if (el.hasAttribute('popover')) {
            el.removeAttribute('popover');
        }
    };
    const lift = el => {
        if (!el.isConnected || el.hidden) {
            return;
        }
        drop(el);
        el.setAttribute('popover', 'manual');
        try {
            el.showPopover();
        } catch {
            drop(el);
            return;
        }
        if (el.matches('.dropdown-panel')) {
            place(el);
        }
    };
    const start = () => {
        document.querySelectorAll(LAYER).forEach(lift);
        new MutationObserver(records => {
            const seen = new Set();
            records.forEach(record => {
                if (record.type === 'attributes') {
                    if (record.target.matches?.(LAYER)) {
                        seen.add(record.target);
                    }
                    return;
                }
                record.addedNodes.forEach(node => {
                    if (node.nodeType === 1) {
                        if (node.matches(LAYER)) {
                            seen.add(node);
                        }
                        node.querySelectorAll(LAYER).forEach(el => seen.add(el));
                    }
                });
            });
            // The tooltip is lifted again each time it shows, so it lies above a dialog opened after it.
            seen.forEach(el => {
                if (el.hidden) {
                    drop(el);
                } else if (!el.matches(':popover-open') || el.matches('.tip')) {
                    lift(el);
                }
            });
        }).observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['hidden'] });
        const reposition = () => document.querySelectorAll('.dropdown-panel:popover-open').forEach(place);
        addEventListener('resize', reposition);
        document.addEventListener('scroll', event => {
            if (!event.target.closest?.('.dropdown-panel')) {
                reposition();
            }
        }, true);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
