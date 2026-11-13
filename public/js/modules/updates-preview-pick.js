(() => {
    // Update page: a row's "Aktualisieren" or "Wechseln" puts its pull request number into the preview dialogs (A54, task 109).
    const setupPreviewPick = () => {
        document.addEventListener('click', event => {
            const button = event.target.closest('[data-preview-pr]');
            if (button) {
                document.querySelectorAll('[data-preview-pr-field]').forEach(field => { field.value = button.dataset.previewPr; });
            }
        }, { capture: true });
    };

    document.addEventListener('DOMContentLoaded', setupPreviewPick);
})();
