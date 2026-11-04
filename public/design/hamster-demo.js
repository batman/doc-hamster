/* Demo for the 3D mascot: feed documents by dragging them onto the hamster. */
(function () {
    'use strict';

    var mascot = window.docHamster3d.mountMascot(window.THREE, { size: 192, px: 2 });

    document.querySelectorAll('[data-play]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-play');
            if (id === 'eat') { mascot.eat(); } else { mascot.play(id, 8); }
        });
    });
    document.getElementById('hd-auto').addEventListener('change', function (e) {
        mascot.setAutonomous(e.target.checked);
    });

    var docs = document.querySelector('.hd-docs');
    var names = ['Rechnung 0815', 'Quittung Bäckerei', 'Tankbeleg'];
    document.getElementById('hd-refill').addEventListener('click', function () {
        names.forEach(function (n) {
            var d = document.createElement('div');
            d.className = 'hd-doc';
            d.setAttribute('data-food', '');
            d.textContent = n;
            docs.appendChild(d);
        });
    });

    // Drag a document with the pointer; dropped on the hamster it gets eaten.
    var drag = null;
    document.addEventListener('pointerdown', function (e) {
        var el = e.target.closest('[data-food]');
        if (!el || drag) { return; }
        e.preventDefault();
        e.stopPropagation();
        var r = el.getBoundingClientRect();
        drag = { el: el, dx: e.clientX - r.left, dy: e.clientY - r.top, id: e.pointerId };
        el.classList.add('is-dragging');
        move(e.clientX, e.clientY);
    }, true);
    function move(x, y) {
        drag.el.style.left = (x - drag.dx) + 'px';
        drag.el.style.top = (y - drag.dy) + 'px';
        drag.el.classList.toggle('is-over', mascot.hitTest(x, y));
    }
    window.addEventListener('pointermove', function (e) {
        if (drag && e.pointerId === drag.id) { move(e.clientX, e.clientY); }
    });
    window.addEventListener('pointerup', function (e) {
        if (!drag || e.pointerId !== drag.id) { return; }
        var el = drag.el;
        drag = null;
        el.classList.remove('is-dragging', 'is-over');
        el.style.left = el.style.top = '';
        if (mascot.hitTest(e.clientX, e.clientY) && mascot.eat()) {
            el.parentNode.removeChild(el);
        }
    });
})();
