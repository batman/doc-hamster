(() => {
    const DocHamster = window.DocHamster;
    const root = document.documentElement;
    // Tag colour: a palette colour, or any colour from the picker or typed as #rrggbb.
    // Colour field (tags, folders): set up in every window, also in windows loaded later (window system).
    const setupColorField = root => root.querySelectorAll('[data-color-field]:not([data-ready])').forEach(field => {
        field.dataset.ready = '';
        const picker = field.querySelector('[data-color-picker]');
        const hex = field.querySelector('[data-color-hex]');
        const radios = [...field.querySelectorAll('input[type="radio"]')];
        const own = value => {
            radios.forEach(radio => { radio.checked = false; });
            hex.value = value;
        };
        picker.addEventListener('input', () => own(picker.value));
        hex.addEventListener('input', () => {
            if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) {
                picker.value = hex.value.toLowerCase();
            }
            radios.forEach(radio => { radio.checked = false; });
        });
        radios.forEach(radio => radio.addEventListener('change', () => {
            hex.value = '';
            if (radio.value.startsWith('#')) {
                picker.value = radio.value;
            }
        }));
    });
    DocHamster.on('window:open', setupColorField);
})();
