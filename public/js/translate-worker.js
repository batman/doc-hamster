/* Point 172.1: translation in the browser – a Web Worker running Mozilla's bergamot-translator wasm (MPL-2.0, the
 * Firefox ESR 140 engine; JS glue in /vendor/bergamot, wasm and models from the installed packs). Started by
 * modules/translate.js; not in config/assets.php (it runs in its own thread). The setup follows Firefox's
 * translations-engine.worker.js: models in aligned wasm memory, one BlockingService, a second model = English pivot.
 *
 * Messages in:  { id, glue, engine, steps: [{ from, to, files: { model, lex, vocab } }], lines: [string] }
 * Messages out: { id, lines: [string] } or { id, error: string }
 */
'use strict';

const ALIGNMENT = { model: 256, lex: 64, vocab: 64 };
const CONFIG = {
    'beam-size': '1', normalize: '1.0', 'word-penalty': '0', 'max-length-break': '128', 'mini-batch-words': '1024',
    workspace: '128', 'max-length-factor': '2.0', 'skip-cost': 'true', 'cpu-threads': '0', quiet: 'true',
    'quiet-translation': 'true', 'gemm-precision': 'int8shiftAlphaAll', alignment: 'soft',
};

let bergamot = null; // Promise of the wasm module
let service = null;
const models = new Map(); // "from-to" => Promise of a TranslationModel

const load = url => fetch(url, { credentials: 'same-origin' }).then(response => {
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return response.arrayBuffer();
});

const start = (glue, engine) => {
    if (bergamot) return bergamot;
    bergamot = load(engine).then(wasmBinary => new Promise((resolve, reject) => {
        importScripts(glue);
        const module = self.loadBergamot({
            INITIAL_MEMORY: 234_291_200,
            print: () => {},
            printErr: () => {},
            wasmBinary,
            onAbort: () => reject(new Error('wasm abort')),
            onRuntimeInitialized: () => Promise.resolve().then(() => resolve(module)),
        });
    }));
    bergamot.catch(() => { bergamot = null; });
    return bergamot;
};

// Marian wants its options one per line, indented.
const config = () => '\n' + Object.entries(CONFIG).map(([key, value]) => `            ${key}: ${value}\n`).join('') + '            ';

const model = (b, step) => {
    const key = `${step.from}-${step.to}`;
    if (!models.has(key)) {
        const made = Promise.all(['model', 'lex', 'vocab'].map(role => load(step.files[role]))).then(([m, l, v]) => {
            const aligned = (buffer, role) => {
                const memory = new b.AlignedMemory(buffer.byteLength, ALIGNMENT[role]);
                memory.getByteArrayView().set(new Uint8Array(buffer));
                return memory;
            };
            const vocabs = new b.AlignedMemoryList();
            vocabs.push_back(aligned(v, 'vocab'));
            return new b.TranslationModel(step.from, step.to, config(), aligned(m, 'model'), aligned(l, 'lex'), vocabs, null);
        });
        made.catch(() => models.delete(key));
        models.set(key, made);
    }
    return models.get(key);
};

const translate = async ({ glue, engine, steps, lines }) => {
    const b = await start(glue, engine);
    const chain = await Promise.all(steps.map(step => model(b, step)));
    service ??= new b.BlockingService({ cacheSize: 0 });
    const messages = new b.VectorString();
    const options = new b.VectorResponseOptions();
    try {
        lines.forEach(line => {
            messages.push_back(line);
            options.push_back({ qualityScores: false, alignment: false, html: false });
        });
        const responses = chain.length === 2
            ? service.translateViaPivoting(chain[0], chain[1], messages, options)
            : service.translate(chain[0], messages, options);
        const out = [];
        for (let i = 0; i < responses.size(); i++) out.push(responses.get(i).getTranslatedText());
        responses.delete?.();
        return out;
    } finally {
        messages.delete();
        options.delete();
    }
};

self.addEventListener('message', event => {
    const job = event.data ?? {};
    translate(job).then(
        lines => self.postMessage({ id: job.id, lines }),
        error => self.postMessage({ id: job.id, error: String(error?.message ?? error) }),
    );
});
