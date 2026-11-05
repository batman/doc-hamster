// Browser capture: a live camera where the phone allows it, otherwise the
// file input below (which also works with script off). The snapshot goes
// through a canvas capped at 3000 px, so EXIF and GPS data never leave the
// phone; it is then placed into the form and sent to documents.store like
// a picked file, keeping the same field, CSRF and redirect contract.
(() => {
    const root = document.getElementById('capture');
    if (!root) {
        return;
    }
    const live = root.querySelector('[data-live]');
    const preview = document.getElementById('capture-preview');
    const canvas = document.getElementById('capture-canvas');
    const start = root.querySelector('[data-start-camera]');
    const take = root.querySelector('[data-take-photo]');
    const status = root.querySelector('[data-status]');
    const form = root.querySelector('[data-capture-form]');
    const input = root.querySelector('[data-capture]');

    const say = (message, isError = false) => {
        status.textContent = message;
        status.className = isError && message !== '' ? 'error' : '';
    };

    let stream = null;
    const stop = () => {
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }
        preview.srcObject = null;
        preview.hidden = true;
    };

    start.addEventListener('click', () => {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            say(root.dataset.noCamera, true);
            return;
        }
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then((opened) => {
            stream = opened;
            preview.srcObject = stream;
            preview.hidden = false;
            preview.play();
            start.hidden = true;
            take.hidden = false;
            say(root.dataset.hint);
        }).catch(() => {
            say(root.dataset.denied, true);
        });
    });

    take.addEventListener('click', () => {
        if (!preview.videoWidth) {
            return;
        }
        const scale = Math.min(1, 3000 / Math.max(preview.videoWidth, preview.videoHeight));
        canvas.width = Math.round(preview.videoWidth * scale);
        canvas.height = Math.round(preview.videoHeight * scale);
        canvas.getContext('2d').drawImage(preview, 0, 0, canvas.width, canvas.height);
        canvas.toBlob((blob) => {
            if (!blob) {
                say(root.dataset.failed, true);
                return;
            }
            stop();
            take.hidden = true;
            start.hidden = false;
            const files = new DataTransfer();
            files.items.add(new File([blob], 'capture.jpg', { type: 'image/jpeg' }));
            input.files = files.files;
            say(root.dataset.sending);
            form.submit();
        }, 'image/jpeg', 0.9);
    });

    // A picked file uploads straight away; the button stays for script-off.
    input.addEventListener('change', () => {
        if (input.files.length > 0) {
            say(root.dataset.sending);
            form.submit();
        }
    });

    live.hidden = false;
})();
