/* SleekAI — Livestream page. Runs the Decart realtime session in the browser.
 *
 * Order matters for money: the camera is requested BEFORE anything is charged, so a
 * denied permission costs nothing. Once charged, every exit path — End button,
 * Decart disconnect, error, time limit, closing the tab — reports the seconds used
 * so unused time can be refunded.
 */

// Pinned: a new SDK version must be tested before the page picks it up.
const SDK_URL = 'https://cdn.jsdelivr.net/npm/@decartai/sdk@0.2.1/+esm';
const DEFAULT_LOOK = 'Transform into the person in this photo, keeping my movements and expressions';
const TICK_MS = 5000;
const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const PHOTO_MAX = 5 * 1024 * 1024;

const root = document.querySelector('[data-live]');
const $ = (id) => document.getElementById(id);
const ui = {
    form: $('liveForm'), go: $('liveGo'), photo: $('livePhoto'), preview: $('livePhotoPreview'), dot: $('livePhotoDot'),
    prompt: $('livePrompt'), consent: $('liveConsent'), out: $('liveOut'), self: $('liveSelf'),
    placeholder: $('livePlaceholder'), status: $('liveStatus'), clock: $('liveClock'),
    controls: $('liveControls'), restyle: $('liveRestyle'), full: $('liveFull'), end: $('liveEnd'), msg: $('liveMessage'),
};

let sdk = null;
let live = null;   // { job, rt, stream, maxSeconds, generatingSince, generatedMs, ticker, clockTimer, ended }

/* ------------------------------------------------------------------ helpers */

function say(text, kind = 'info', buy = false) {
    const style = { info: 'border-signal/40 bg-signal/10', error: 'border-tally/40 bg-tally/10', success: 'border-reel/40 bg-reel/10' }[kind];
    ui.msg.className = `mt-3 rounded-lg border px-4 py-3 text-[14px] text-paper ${style}`;
    ui.msg.textContent = text;
    if (buy) {
        const a = document.createElement('a');
        a.href = 'credits-billing';
        a.className = 'ml-1 font-medium text-signal underline';
        a.textContent = 'Buy credits';
        ui.msg.append(' ', a);
    }
}

function setStatus(label, tone) {
    const colors = { idle: 'bg-muted', wait: 'bg-signal', live: 'bg-tally', done: 'bg-reel' };
    ui.status.classList.remove('hidden');
    ui.status.classList.add('flex');
    ui.status.querySelector('[data-text]').textContent = label;
    ui.status.querySelector('[data-dot]').className = `h-1.5 w-1.5 rounded-full ${colors[tone] || colors.idle}`;
}

function api(fields, keepalive = false) {
    const body = new FormData();
    body.append('_token', root.dataset.csrf);
    body.append('format', 'json');
    for (const [k, v] of Object.entries(fields)) body.append(k, String(v));
    return fetch(root.dataset.api, { method: 'POST', body, credentials: 'same-origin', keepalive })
        .then(async (r) => ({ status: r.status, data: await r.json().catch(() => ({ ok: false, error: 'Unexpected response.' })) }));
}

/** Seconds actually generated: wall time spent in the 'generating' state. */
function usedSeconds() {
    if (!live) return 0;
    const running = live.generatingSince ? performance.now() - live.generatingSince : 0;
    return Math.min(live.maxSeconds, Math.floor((live.generatedMs + running) / 1000));
}

function fmt(sec) {
    const m = Math.floor(sec / 60), s = sec % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

function selectedPhoto() {
    const f = ui.photo.files && ui.photo.files[0];
    if (!f) return { error: 'Choose a reference photo first.' };
    if (!PHOTO_TYPES.includes(f.type)) return { error: 'The reference photo must be a JPEG, PNG or WebP image.' };
    if (f.size > PHOTO_MAX) return { error: 'The reference photo must be under 5 MB.' };
    return { file: f };
}

/* ---------------------------------------------------------------- setup ui */

ui.photo.addEventListener('change', () => {
    const { file, error } = selectedPhoto();
    if (error) { say(error, 'error'); return; }
    ui.msg.classList.add('hidden');
    const url = URL.createObjectURL(file);
    ui.preview.src = url;
    ui.preview.classList.remove('hidden');
    ui.dot.classList.add('hidden');
    const img = new Image();
    img.onload = () => {
        if (Math.min(img.naturalWidth, img.naturalHeight) < 512) {
            say('That photo is small — at least 512×512 gives a much better likeness.', 'info');
        }
    };
    img.src = url;
});

/* ------------------------------------------------------------------ go live */

ui.form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (live) return;

    const { file, error } = selectedPhoto();
    if (error) return say(error, 'error');
    if (!ui.consent.checked) return say('Confirm you have permission to use the face in your reference photo.', 'error');
    const minutes = ui.form.querySelector('input[name="minutes"]:checked');
    if (!minutes) return say('Pick a session length you can afford.', 'error', true);

    ui.go.disabled = true;
    ui.go.textContent = 'Starting camera…';
    ui.msg.classList.add('hidden');

    // 1. Camera first — nothing has been charged yet.
    const model = sdk.models.realtime(root.dataset.model || 'lucy-2.1');
    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { frameRate: model.fps, width: { ideal: model.width }, height: { ideal: model.height } },
            audio: false,
        });
    } catch (e) {
        resetForm();
        return say(e && e.name === 'NotAllowedError'
            ? 'Camera access was blocked. Allow it in your browser\'s address bar, then try again. You haven\'t been charged.'
            : 'No camera was found, or it\'s in use by another app. You haven\'t been charged.', 'error');
    }
    ui.self.srcObject = stream;
    ui.self.classList.remove('hidden');

    // 2. Charge and get a token.
    ui.go.textContent = 'Starting session…';
    const { status, data } = await api({ action: 'start', minutes: minutes.value, consent: 1 }).catch(() => ({ status: 0, data: {} }));
    if (!data.ok) {
        stopCamera(stream);
        resetForm();
        return say(data.error || 'Couldn\'t reach SleekAI. You haven\'t been charged.', 'error', !!data.buy || status === 402);
    }

    live = { job: data.job, rt: null, stream, maxSeconds: data.maxSeconds, generatingSince: 0, generatedMs: 0, ended: false };
    root.dataset.job = String(data.job);
    setStatus('CONNECTING', 'wait');
    ui.clock.classList.remove('hidden');
    ui.clock.textContent = `${fmt(live.maxSeconds)} left`;

    // 3. Connect straight to Decart with the short-lived token.
    try {
        const client = sdk.createDecartClient({ apiKey: data.token });
        const rt = await client.realtime.connect(stream, {
            model: sdk.models.realtime(data.model),
            onRemoteStream: (remote) => {
                ui.out.srcObject = remote;
                ui.placeholder.classList.add('hidden');
            },
            initialState: {
                prompt: { text: ui.prompt.value.trim() || DEFAULT_LOOK, enhance: true },
                image: file,
            },
        });
        live.rt = rt;

        rt.on('connectionChange', (state) => {
            if (!live || live.ended) return;
            if (state === 'generating') {
                if (!live.generatingSince) live.generatingSince = performance.now();
                setStatus('LIVE', 'live');
            } else {
                if (live.generatingSince) { live.generatedMs += performance.now() - live.generatingSince; live.generatingSince = 0; }
                if (state === 'disconnected') finish('Decart ended the stream.');
                else setStatus(state === 'reconnecting' ? 'RECONNECTING' : 'CONNECTING', 'wait');
            }
        });
        rt.on('error', (e) => finish('The stream hit an error: ' + ((e && e.message) || 'unknown') + '.', true));
        if (rt.getConnectionState && rt.getConnectionState() === 'generating' && !live.generatingSince) {
            live.generatingSince = performance.now();
            setStatus('LIVE', 'live');
        }
    } catch (e) {
        return finish('Couldn\'t connect to the video service' + (e && e.message ? ` (${e.message})` : '') + '.', true);
    }

    ui.form.classList.add('pointer-events-none', 'opacity-60');
    ui.controls.classList.remove('hidden');
    ui.controls.classList.add('flex');
    ui.go.textContent = 'Live';

    // 4. Keep the server informed, and count down.
    live.ticker = setInterval(async () => {
        if (!live || live.ended) return;
        const r = await api({ action: 'tick', job: live.job, seconds: usedSeconds() }).catch(() => null);
        if (r && r.data && r.data.ended) finish('This session was ended elsewhere.');
    }, TICK_MS);
    live.clockTimer = setInterval(() => {
        if (!live || live.ended) return;
        const left = Math.max(0, live.maxSeconds - usedSeconds());
        ui.clock.textContent = `${fmt(left)} left`;
        if (left === 0) finish('You reached the end of your session.');
    }, 500);
});

/* ------------------------------------------------------------------- finish */

async function finish(reason, isError = false) {
    if (!live || live.ended) return;
    live.ended = true;
    if (live.generatingSince) { live.generatedMs += performance.now() - live.generatingSince; live.generatingSince = 0; }
    clearInterval(live.ticker);
    clearInterval(live.clockTimer);

    const seconds = usedSeconds();
    try { live.rt && live.rt.disconnect(); } catch (_) { /* already closed */ }
    stopCamera(live.stream);
    ui.out.srcObject = null;
    ui.placeholder.classList.remove('hidden');
    ui.controls.classList.add('hidden');
    ui.controls.classList.remove('flex');
    ui.clock.classList.add('hidden');
    setStatus('ENDED', 'done');

    // Ready for another go straight away — whether this ended well or failed to connect.
    ui.form.classList.remove('pointer-events-none', 'opacity-60');
    resetForm();

    const { data } = await api({ action: 'end', job: live.job, seconds }, true).catch(() => ({ data: {} }));
    say((reason ? reason + ' ' : '') + (data.message || 'Session ended.'), isError && data.status === 'failed' ? 'error' : 'success');

    const again = document.createElement('button');
    again.type = 'button';
    again.className = 'ml-2 font-medium text-signal underline';
    again.textContent = 'Refresh';
    again.onclick = () => location.reload();
    ui.msg.append(' ', again);
    live = null;
}

function stopCamera(stream) {
    if (stream) stream.getTracks().forEach((t) => t.stop());
    ui.self.srcObject = null;
    ui.self.classList.add('hidden');
}

function resetForm() {
    ui.go.disabled = false;
    ui.go.textContent = 'Go live';
}

/* ------------------------------------------------------------- live controls */

ui.end.addEventListener('click', () => finish(''));
ui.full.addEventListener('click', () => ui.out.requestFullscreen && ui.out.requestFullscreen().catch(() => {}));
ui.restyle.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (!live || !live.rt) return;
    const text = new FormData(ui.restyle).get('prompt').toString().trim();
    const { file } = selectedPhoto();
    try {
        // set() replaces the whole state, so resend the photo with the new look.
        await live.rt.set({ prompt: text || DEFAULT_LOOK, image: file, enhance: true });
        say('Look updated.', 'success');
    } catch (e) {
        say('Couldn\'t update the look: ' + ((e && e.message) || 'unknown error'), 'error');
    }
});

// Tab closed or navigated away mid-stream: settle with what was used.
window.addEventListener('pagehide', () => {
    if (!live || live.ended) return;
    live.ended = true;
    const body = new FormData();
    body.append('_token', root.dataset.csrf);
    body.append('format', 'json');
    body.append('action', 'end');
    body.append('job', String(live.job));
    body.append('seconds', String(usedSeconds()));
    navigator.sendBeacon(root.dataset.api, body);
    try { live.rt && live.rt.disconnect(); } catch (_) { /* closing anyway */ }
});

/* -------------------------------------------------------------------- boot */

try {
    sdk = await import(SDK_URL);
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        ui.go.textContent = 'Camera not available here';
        say('This browser can\'t use a camera on this page. Livestream needs a secure (https) connection and a modern browser.', 'error');
    } else {
        resetForm();
    }
    root.dataset.ready = 'sdk';
} catch (e) {
    ui.go.textContent = 'Unavailable';
    say('The live video component didn\'t load. Check your connection and refresh.', 'error');
    root.dataset.ready = 'failed';
}
