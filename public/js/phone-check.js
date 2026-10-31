// Device probe for the phone website (#37). Runs only in the browser, nothing leaves the phone.
(() => {
    const root = document.getElementById('phone-checks');
    const video = document.getElementById('camera');

    const set = (name, state, detail = '') => {
        const row = root.querySelector(`[data-check="${name}"]`);
        const label = row.querySelector('.check-state');
        label.textContent = root.dataset[state];
        label.className = `check-state check-${{ ok: 'ok', warn: 'warning', fail: 'fail' }[state]}`;
        row.querySelector('.check-detail').textContent = detail;
    };

    const run = async (name, check) => {
        try {
            await check();
        } catch (error) {
            set(name, 'fail', error.name || String(error));
        }
    };

    const request = (idbRequest) => new Promise((resolve, reject) => {
        idbRequest.onsuccess = () => resolve(idbRequest.result);
        idbRequest.onerror = () => reject(idbRequest.error);
    });

    const openDb = () => {
        const opening = indexedDB.open('dochamster-probe', 1);
        opening.onupgradeneeded = () => opening.result.createObjectStore('keys');

        return request(opening);
    };

    const checkKey = async () => {
        const db = await openDb();
        const store = (mode) => db.transaction('keys', mode).objectStore('keys');
        let entry = await request(store('readonly').get('device'));
        const found = Boolean(entry);

        if (! found) {
            const pair = await crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign', 'verify']);
            entry = { pair, created: Date.now() };
            await request(store('readwrite').put(entry, 'device'));
        }

        const algorithm = { name: 'ECDSA', hash: 'SHA-256' };
        const challenge = crypto.getRandomValues(new Uint8Array(32));
        const signature = await crypto.subtle.sign(algorithm, entry.pair.privateKey, challenge);
        const valid = await crypto.subtle.verify(algorithm, entry.pair.publicKey, signature, challenge);
        const leaked = await crypto.subtle.exportKey('pkcs8', entry.pair.privateKey).then(() => true, () => false);
        const created = new Date(entry.created).toLocaleString();

        set('key', valid && ! leaked ? 'ok' : 'fail', found ? root.dataset.keyFound.replace(':date', created) : root.dataset.keyNew);
    };

    const scan = (stream) => {
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d', { willReadFrequently: true });
        const started = Date.now();

        const tick = () => {
            if (video.videoWidth > 0) {
                const scale = Math.min(1, 800 / video.videoWidth);
                canvas.width = video.videoWidth * scale;
                canvas.height = video.videoHeight * scale;
                context.drawImage(video, 0, 0, canvas.width, canvas.height);

                const code = jsQR(context.getImageData(0, 0, canvas.width, canvas.height).data, canvas.width, canvas.height);

                if (code?.data.startsWith('dochamster-test:')) {
                    set('qr', 'ok', `${((Date.now() - started) / 1000).toFixed(1)} s`);
                    stream.getTracks().forEach((track) => track.stop());
                    video.hidden = true;

                    return;
                }

                if (code) {
                    set('qr', 'warn', root.dataset.otherCode);
                }
            }

            setTimeout(tick, 250);
        };

        tick();
    };

    const startCamera = async () => {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 } },
            audio: false,
        });

        video.srcObject = stream;
        video.hidden = false;
        await video.play();

        const { width, height } = stream.getVideoTracks()[0].getSettings();
        set('camera', 'ok', `${width} × ${height}`);
        scan(stream);
    };

    const megabytes = (file) => `${(file.size / 1048576).toFixed(1)} MB`;

    const describe = async (name, file) => {
        const size = await createImageBitmap(file).then(
            (image) => `, ${image.width} × ${image.height}`,
            () => '',
        );

        set(name, 'ok', `${file.type || '?'}, ${megabytes(file)}${size}`);
    };

    document.getElementById('start-camera').addEventListener('click', () => run('camera', startCamera));

    for (const [id, name] of [['photo-input', 'photo'], ['file-input', 'file']]) {
        document.getElementById(id).addEventListener('change', (event) => {
            const [file] = event.target.files;

            if (file) {
                run(name, () => describe(name, file));
            }
        });
    }

    const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    set('standalone', standalone ? 'ok' : 'warn');
    run('key', checkKey);
    run('storage', async () => set('storage', await navigator.storage.persist() ? 'ok' : 'warn'));

    document.getElementById('phone-check-device').textContent = navigator.userAgent;
})();
