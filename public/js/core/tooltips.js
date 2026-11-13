(() => {
    const root = document.documentElement;
    // Tooltips (A103): one layer on <body> above everything, so cards, columns and scrolling windows can't cut
    // them off. The element's ::after rules still decide whether its tooltip shows at all; the side comes
    // from .tooltip-right (else below, or above when there is no room). Mouse and keyboard only, not touch.
    const setupTips = () => {
        const tip = document.createElement('div');
        tip.className = 'tip';
        tip.setAttribute('role', 'tooltip');
        tip.hidden = true;
        document.body.append(tip);
        root.classList.add('has-tips');
        let owner = null;
        let timer = 0;
        const hide = () => {
            clearTimeout(timer);
            owner = null;
            tip.classList.remove('is-shown');
            tip.hidden = true;
        };
        const place = el => {
            const box = el.getBoundingClientRect();
            const right = el.classList.contains('tooltip-right');
            tip.hidden = false;
            const w = tip.offsetWidth;
            const h = tip.offsetHeight;
            let x;
            let y;
            let side;
            if (right && box.right + 10 + w < innerWidth) {
                side = 'right';
                x = box.right + 10;
                y = box.top + box.height / 2 - h / 2;
            } else {
                side = box.bottom + 10 + h > innerHeight ? 'above' : 'below';
                x = Math.min(Math.max(8, box.left + box.width / 2 - w / 2), innerWidth - w - 8);
                y = side === 'below' ? box.bottom + 10 : box.top - 10 - h;
                tip.style.setProperty('--tip-arrow', `${box.left + box.width / 2 - x}px`);
            }
            tip.dataset.side = side;
            tip.style.left = `${Math.round(x)}px`;
            tip.style.top = `${Math.round(y)}px`;
        };
        const show = el => {
            if (el === owner) {
                return;
            }
            hide();
            if (!el.dataset.tooltip || getComputedStyle(el, '::after').display === 'none') {
                return;
            }
            owner = el;
            timer = setTimeout(() => {
                if (owner !== el || !el.isConnected) {
                    return;
                }
                tip.textContent = el.dataset.tooltip;
                place(el);
                tip.classList.add('is-shown');
            }, 300);
        };
        document.addEventListener('pointerover', event => {
            if (event.pointerType === 'touch') {
                return;
            }
            const el = event.target.closest?.('[data-tooltip]');
            if (el) {
                show(el);
            } else if (owner) {
                hide();
            }
        });
        document.addEventListener('pointerout', event => {
            if (owner && !owner.contains(event.relatedTarget)) {
                hide();
            }
        });
        document.addEventListener('focusin', event => {
            const el = event.target.closest?.('[data-tooltip]');
            if (el?.matches(':focus-visible')) {
                show(el);
            }
        });
        document.addEventListener('focusout', hide);
        document.addEventListener('pointerdown', hide, true);
        document.addEventListener('scroll', hide, true);
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                hide();
            }
        });
        // A label that changes while shown (maximise / restore) updates the tooltip.
        new MutationObserver(() => {
            if (owner && !tip.hidden) {
                tip.textContent = owner.dataset.tooltip;
                place(owner);
            }
        }).observe(document.body, { subtree: true, attributeFilter: ['data-tooltip'] });
    };

    document.addEventListener('DOMContentLoaded', setupTips);
    // The active tab stays in view when the tab bar scrolls sideways (A93).
    document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('.tab-bar [aria-current="page"]').forEach(tab => tab.scrollIntoView({ block: 'nearest', inline: 'nearest' })));
})();
