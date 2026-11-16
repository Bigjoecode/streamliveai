/* LucaAi — Livestream page.
 *
 * Engines: XMAX X2.1 / X2.0 and Decart Lucy (fast mode). The browser talks to the
 * provider directly with a short-lived key the server issues per session.
 *
 * Order matters for money: the camera is requested BEFORE anything is charged, so
 * a denied permission costs nothing. Once charged, every exit path — End button,
 * provider disconnect, error, time limit, closing the tab — reports the seconds
 * used so unused time can be refunded.
 *
 * Outbound video is copied through a canvas only when it needs a square crop for
 * Telegram video notes; the stream and recording contain no added overlay.
 */

// Pinned: a new SDK version must be tested before the page picks it up.
const DECART_SDK = 'https://cdn.jsdelivr.net/npm/@decartai/sdk@0.2.14/+esm';
const XMAX_SDK   = 'https://cdn.jsdelivr.net/npm/@xmaxai/web-sdk@1.1.5/dist/browser/index.js';
const DAILY_SDK  = 'https://cdn.jsdelivr.net/npm/@daily-co/daily-js@0.94.0/dist/daily-esm.js';

// Decart: a photoreal likeness that follows the camera closely; the photo carries the identity.
const DECART_LOOK = 'Replace me with the person in the reference photo: same face, hair and skin tone, photorealistic, '
    + 'natural lighting and skin texture. Follow my exact head movements, facial expressions, lip movements and gestures.';
// XMAX: the character-swap instruction from XMAX's own guide ("replace the person in the video with the reference").
const XMAX_LOOK = '视频中角色替换成参考图中角色';

const TICK_MS = 5000;
const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const PHOTO_MAX = 10 * 1024 * 1024;
const CLIP_MAX_SEC = 60;

const root = document.querySelector('[data-live]');
const $ = (id) => document.getElementById(id);
const ui = {
    form: $('liveForm'), go: $('liveGo'), photo: $('livePhoto'), preview: $('livePhotoPreview'), dot: $('livePhotoDot'),
    prompt: $('livePrompt'), promptCount: $('livePromptCount'), consent: $('liveConsent'),
    bgToggle: $('liveBgToggle'), bgGrid: $('liveBgGrid'),
    stage: $('liveStage'), out: $('liveOut'), self: $('liveSelf'), placeholder: $('livePlaceholder'),
    status: $('liveStatus'), clock: $('liveClock'), quality: $('liveQuality'), msg: $('liveMessage'),
    bar: $('liveBar'), mic: $('liveMic'), cam: $('liveCam'), full: $('liveFull'), end: $('liveEnd'), restyle: $('liveRestyle'),
    chipCam: $('chipCam'), chipNet: $('chipNet'), chipNetBtn: $('chipNetBtn'), chipConsent: $('chipConsent'),
    tgConnect: $('tgConnect'), tgState: $('tgState'), tgRec: $('tgRec'), tgStop: $('tgStop'), tgSend: $('tgSend'),
    tgDiscard: $('tgDiscard'), tgPreview: $('tgPreview'),
    callStart: $('callStart'), callLink: $('callLink'), callCopy: $('callCopy'), callCount: $('callCount'), callTiles: $('callTiles'),
    netRun: $('netRun'), netDown: $('netDown'), netLat: $('netLat'), netRate: $('netRate'), netNote: $('netNote'),
};

let live = null;   // the running session — see start()
const sdkCache = {};
const loadSdk = (url) => (sdkCache[url] ??= import(url));

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

function setChip(el, text, tone) {
    el.textContent = text;
    el.className = `block text-[12px] ${{ ok: 'text-reel', warn: 'text-signal', bad: 'text-tally', muted: 'text-muted' }[tone] || 'text-muted'}`;
}

function show(el, on, display = 'flex') {
    el.classList.toggle('hidden', !on);
    el.classList.toggle(display, on);
}

function api(fields, keepalive = false) {
    const body = new FormData();
    body.append('_token', root.dataset.csrf);
    body.append('format', 'json');
    for (const [k, v] of Object.entries(fields)) body.append(k, v instanceof Blob ? v : String(v));
    return fetch(root.dataset.api, { method: 'POST', body, credentials: 'same-origin', keepalive })
        .then(async (r) => ({ status: r.status, data: await r.json().catch(() => ({ ok: false, error: 'Unexpected response.' })) }));
}

const r1 = (n) => (typeof n === 'number' && isFinite(n) ? Math.round(n * 10) / 10 : null);

function fmt(sec) {
    const m = Math.floor(sec / 60), s = sec % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

/** Seconds actually generated: wall time spent in the generating state. */
function usedSeconds() {
    if (!live) return 0;
    const running = live.generatingSince ? performance.now() - live.generatingSince : 0;
    return Math.min(live.maxSeconds, Math.floor((live.generatedMs + running) / 1000));
}

function markGenerating(on) {
    if (!live || live.ended) return;
    if (on && !live.generatingSince) {
        live.generatingSince = performance.now();
        setStatus('LIVE', 'live');
        setChip(ui.chipCam, 'Live', 'ok');
    } else if (!on && live.generatingSince) {
        live.generatedMs += performance.now() - live.generatingSince;
        live.generatingSince = 0;
    }
}

function selectedPhoto() {
    const f = ui.photo.files && ui.photo.files[0];
    if (!f) return { error: 'Upload a source face first.' };
    if (!PHOTO_TYPES.includes(f.type)) return { error: 'The face image must be a JPG, PNG or WEBP.' };
    if (f.size > PHOTO_MAX) return { error: 'The face image must be under 10 MB.' };
    return { file: f };
}

/** Style prompt + background, as one line of extra detail. */
function extras(styleText) {
    const bits = [];
    const style = (styleText ?? ui.prompt.value).trim();
    if (style) bits.push(style);
    const bgOn = ui.bgToggle.getAttribute('aria-checked') === 'true';
    const bg = ui.form.querySelector('input[name="background"]:checked');
    if (bgOn && bg && bg.dataset.prompt) bits.push(`background: ${bg.dataset.prompt}`);
    return bits.join(', ');
}

function promptFor(provider, styleText) {
    const more = extras(styleText);
    if (provider === 'xmax') return more ? `${XMAX_LOOK}。${more}` : XMAX_LOOK;
    return more ? `${DECART_LOOK} Style: ${more}.` : DECART_LOOK;
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
        if (Math.min(img.naturalWidth, img.naturalHeight) < 512) say('That photo is small — at least 512×512 gives a much better likeness.', 'info');
    };
    img.src = url;
});

const countPrompt = () => { ui.promptCount.textContent = String(ui.prompt.value.length); };
ui.prompt.addEventListener('input', countPrompt);
document.querySelectorAll('[data-chip]').forEach((b) => b.addEventListener('click', () => {
    const add = b.dataset.chip;
    const cur = ui.prompt.value.trim();
    if (cur.toLowerCase().includes(add)) return;
    ui.prompt.value = (cur ? `${cur}, ${add}` : add).slice(0, 150);
    countPrompt();
}));

ui.bgToggle.addEventListener('click', () => {
    const on = ui.bgToggle.getAttribute('aria-checked') !== 'true';
    ui.bgToggle.setAttribute('aria-checked', String(on));
    ui.bgToggle.querySelector('[data-knob]').style.transform = on ? 'translateX(20px)' : '';
    ui.bgToggle.querySelector('[data-knob]').classList.toggle('bg-signal', on);
    show(ui.bgGrid, on, 'grid');
});

ui.consent.addEventListener('change', () => {
    setChip(ui.chipConsent, ui.consent.checked ? 'Confirmed' : 'Not confirmed yet', ui.consent.checked ? 'ok' : 'muted');
});

/* ------------------------------------------------------------- network test */

async function networkTest() {
    ui.netRun.disabled = true;
    ui.netNote.textContent = 'Testing…';
    setChip(ui.chipNet, 'Testing…', 'warn');
    try {
        const pings = [];
        for (let i = 0; i < 5; i++) {
            const t = performance.now();
            const r = await fetch(`${root.dataset.net}?t=ping&n=${Date.now()}${i}`, { cache: 'no-store', credentials: 'same-origin' });
            await r.text();
            pings.push(performance.now() - t);
        }
        pings.sort((a, b) => a - b);
        const lat = Math.round(pings[2]);

        const t0 = performance.now();
        const r = await fetch(`${root.dataset.net}?t=download&n=${Date.now()}`, { cache: 'no-store', credentials: 'same-origin' });
        const buf = await r.arrayBuffer();
        const sec = Math.max(0.05, (performance.now() - t0 - lat) / 1000);
        const mbps = (buf.byteLength * 8) / sec / 1e6;

        const rating = mbps >= 10 && lat < 100 ? 'Excellent' : mbps >= 5 && lat < 180 ? 'Good' : mbps >= 2 && lat < 300 ? 'Fair' : 'Poor';
        ui.netDown.textContent = `${mbps >= 100 ? Math.round(mbps) : mbps.toFixed(1)} Mbps`;
        ui.netLat.textContent = `${lat} ms`;
        ui.netRate.textContent = rating;
        ui.netNote.textContent = rating === 'Poor' || rating === 'Fair'
            ? 'Your connection may lag or freeze. Move closer to Wi-Fi, close other downloads, or use a wired connection.'
            : 'Your connection should carry a live stream well.';
        setChip(ui.chipNet, `${rating} · ${lat} ms`, rating === 'Poor' ? 'bad' : rating === 'Fair' ? 'warn' : 'ok');
    } catch (_) {
        ui.netNote.textContent = 'The test couldn\'t finish. Check your connection and try again.';
        setChip(ui.chipNet, 'Test failed', 'bad');
    }
    ui.netRun.disabled = false;
}
ui.netRun.addEventListener('click', networkTest);
ui.chipNetBtn.addEventListener('click', networkTest);

/* ------------------------------------------------------------ quality badge */

const QUALITY_TEXT = { good: 'Good', fair: 'Fair', poor: 'Poor', critical: 'Very poor' };
const QUALITY_DOT = { good: 'bg-reel', fair: 'bg-signal', poor: 'bg-tally', critical: 'bg-tally' };
const LIMIT_TEXT = { bandwidth: 'upload speed', latency: 'network delay', loss: 'packet loss', stall: 'stalls', cpu: 'device CPU' };

function showQuality(quality, why = '', warming = false) {
    ui.quality.innerHTML = '';
    const dot = document.createElement('span');
    dot.className = `h-1.5 w-1.5 rounded-full ${QUALITY_DOT[quality] || 'bg-muted'}`;
    const text = document.createElement('span');
    text.textContent = warming ? 'Connection: measuring…' : `Connection: ${QUALITY_TEXT[quality] || quality}${why ? ` · ${why}` : ''}`;
    ui.quality.append(dot, text);
    show(ui.quality, true);
}

function recordQuality(sample) {
    if (!live) return;
    live.diag.samples.push({ t: Math.round((performance.now() - live.diag.t0) / 1000), ...sample });
    if (live.diag.samples.length > 40) live.diag.samples.shift();
}

function diagPayload() {
    if (!live || !live.diag) return '';
    const s = JSON.stringify(live.diag);
    return s.length > 7500 ? JSON.stringify({ ...live.diag, samples: live.diag.samples.slice(-15) }) : s;
}

/* --------------------------------------------------------- outbound video */

/**
 * A canvas copy used for outbound video. square=true crops the middle for
 * Telegram's round video notes.
 */
function outboundStream(square) {
    const w = square ? 480 : 1280, h = square ? 480 : 720;
    const canvas = document.createElement('canvas');
    canvas.width = w; canvas.height = h;
    const ctx = canvas.getContext('2d');
    let stopped = false;

    const draw = () => {
        const v = ui.out;
        if (v.videoWidth) {
            const sw = v.videoWidth, sh = v.videoHeight;
            const scale = Math.max(w / sw, h / sh);
            const dw = sw * scale, dh = sh * scale;
            ctx.drawImage(v, (w - dw) / 2, (h - dh) / 2, dw, dh);
        } else {
            ctx.fillStyle = '#0b1020';
            ctx.fillRect(0, 0, w, h);
        }
    };
    // A timer keeps drawing when the tab is in the background (requestAnimationFrame would stop).
    const timer = setInterval(() => { if (!stopped) draw(); }, 1000 / 30);
    draw();
    const stream = canvas.captureStream(30);
    return { stream, track: stream.getVideoTracks()[0], stop() { stopped = true; clearInterval(timer); stream.getTracks().forEach((t) => t.stop()); } };
}

/* ------------------------------------------------------------------ go live */

ui.form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (live) return;

    const { file, error } = selectedPhoto();
    if (error) return say(error, 'error');
    if (!ui.consent.checked) return say('Confirm you have the right to use this face.', 'error');
    const minutes = ui.form.querySelector('input[name="minutes"]:checked');
    if (!minutes) return say('Pick a session length you can afford.', 'error', true);
    const engine = ui.form.querySelector('input[name="engine"]:checked');
    if (!engine) return say('Livestream is temporarily unavailable.', 'error');
    const provider = engine.dataset.provider;

    ui.go.disabled = true;
    ui.go.textContent = 'Starting camera…';
    ui.msg.classList.add('hidden');

    // 1. Camera first — nothing has been charged yet. Load the engine's SDK meanwhile.
    const sdkP = loadSdk(provider === 'xmax' ? XMAX_SDK : DECART_SDK);
    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { frameRate: { ideal: 30 }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false,
        });
    } catch (e) {
        resetForm();
        setChip(ui.chipCam, 'Blocked', 'bad');
        return say(e && e.name === 'NotAllowedError'
            ? 'Camera access was blocked. Allow it in your browser\'s address bar, then try again. You haven\'t been charged.'
            : 'No camera was found, or it\'s in use by another app. You haven\'t been charged.', 'error');
    }
    setChip(ui.chipCam, 'Ready', 'ok');
    let sdk;
    try {
        sdk = await sdkP;
    } catch (_) {
        stream.getTracks().forEach((t) => t.stop());
        resetForm();
        return say('The live video component didn\'t load. Check your connection and refresh. You haven\'t been charged.', 'error');
    }

    // 2. Charge and get a short-lived key.
    ui.go.textContent = 'Starting session…';
    const { status, data } = await api({ action: 'start', minutes: minutes.value, engine: engine.value, consent: 1 })
        .catch(() => ({ status: 0, data: {} }));
    if (!data.ok) {
        stream.getTracks().forEach((t) => t.stop());
        resetForm();
        return say(data.error || 'Couldn\'t reach the server. You haven\'t been charged.', 'error', !!data.buy || status === 402);
    }

    const cam = stream.getVideoTracks()[0];
    live = {
        job: data.job, provider: data.provider, maxSeconds: data.maxSeconds, generatingSince: 0, generatedMs: 0, ended: false,
        stream, camTrack: cam, outTrack: null, mic: null, call: null, callFeed: null, rt: null, xmax: null, file,
        diag: { t0: performance.now(), engine: data.engine, model: data.model, speed: data.speed || 'standard', ua: navigator.userAgent,
                cores: navigator.hardwareConcurrency || null, mem: navigator.deviceMemory || null,
                net: navigator.connection ? { type: navigator.connection.effectiveType, down: navigator.connection.downlink, rtt: navigator.connection.rtt } : null,
                cam: cam && cam.getSettings ? (({ width, height, frameRate }) => ({ w: width, h: height, fps: r1(frameRate) }))(cam.getSettings()) : null,
                photo: { kb: Math.round(file.size / 1024), type: file.type },
                prompt: (extras() || 'default').slice(0, 120), samples: [], events: [] },
    };
    root.dataset.job = String(data.job);
    setStatus('CONNECTING', 'wait');
    show(ui.clock, true, 'block');
    ui.clock.textContent = `${fmt(live.maxSeconds)} left`;

    // 3. Connect straight to the provider.
    try {
        if (data.provider === 'xmax') await connectXmax(sdk, data, file);
        else await connectDecart(sdk, data, file);
    } catch (e) {
        return finish('Couldn\'t connect to the video service' + (e && e.message ? ` (${e.message})` : '') + '. Try another AI model.', true);
    }
    if (!live || live.ended) return;

    ui.form.classList.add('pointer-events-none', 'opacity-60');
    show(ui.bar, true);
    show(ui.restyle, true);
    ui.go.textContent = 'Live';
    refreshLiveTools();

    // 4. Keep the server informed, and count down.
    live.ticker = setInterval(async () => {
        if (!live || live.ended) return;
        live.ticks = (live.ticks || 0) + 1;
        const diag = live.ticks % 6 === 0 ? diagPayload() : '';
        const r = await api({ action: 'tick', job: live.job, seconds: usedSeconds(), diag }).catch(() => null);
        if (r && r.data && r.data.ended) finish('This session was ended elsewhere.');
    }, TICK_MS);
    live.clockTimer = setInterval(() => {
        if (!live || live.ended) return;
        const left = Math.max(0, live.maxSeconds - usedSeconds());
        ui.clock.textContent = `${fmt(left)} left`;
        if (left === 0) finish('You reached the end of your session.');
    }, 500);
});

function showOutput(track) {
    if (!live || !track) return;
    live.outTrack = track;
    ui.out.srcObject = new MediaStream([track]);
    ui.placeholder.classList.add('hidden');
}

async function connectDecart(sdk, data, file) {
    ui.self.srcObject = live.stream;
    ui.self.classList.remove('hidden');
    const client = sdk.createDecartClient({ apiKey: data.token });
    const rt = await client.realtime.connect(live.stream, {
        model: sdk.models.realtime(data.model),
        ...(data.speed === 'fast' ? { speed: 'fast' } : {}),
        onRemoteStream: (remote) => showOutput(remote.getVideoTracks()[0]),
        initialState: { prompt: { text: promptFor('decart'), enhance: true }, image: file },
    });
    live.rt = rt;
    rt.on('connectionChange', (state) => {
        if (!live || live.ended) return;
        if (live.diag.events.length < 30) live.diag.events.push([Math.round((performance.now() - live.diag.t0) / 1000), state]);
        if (state === 'generating') return markGenerating(true);
        markGenerating(false);
        if (state === 'disconnected') finish('The video service ended the stream.');
        else setStatus(state === 'reconnecting' ? 'RECONNECTING' : 'CONNECTING', 'wait');
    });
    rt.on('error', (e) => finish('The stream hit an error: ' + ((e && e.message) || 'unknown') + '.', true));
    rt.on('connectionQuality', (q) => {
        if (!q) return;
        const why = q.quality !== 'good' && LIMIT_TEXT[q.limitingFactor] ? LIMIT_TEXT[q.limitingFactor] : '';
        showQuality(q.quality, why, q.warmingUp);
        const m = q.metrics || {};
        recordQuality({ q: q.quality, lim: q.limitingFactor, rtt: r1(m.rttMs), fps: r1(m.fps), loss: r1(m.packetLoss), up: r1(m.availableUpstreamKbps) });
    });
    if (rt.getConnectionState && rt.getConnectionState() === 'generating') markGenerating(true);
}

async function connectXmax(X, data, file) {
    // XMAX opens the camera itself; release the permission-check stream first.
    live.stream.getTracks().forEach((t) => t.stop());

    const client = new X.XmaxClient(new X.XmaxConfiguration({
        apiKey: data.token,
        environment: data.environment === 'china' ? X.XmaxEnvironment.china : X.XmaxEnvironment.global,
        loggerOptions: X.XmaxLoggerOption.none,
    }));
    const rt = client.createRealtimeManager(new X.RealtimeConfiguration({ model: data.model, isAdaptiveQualityEnabled: true }));
    live.xmax = { client, rt, local: null };

    const NET = { Excellent: 'good', Good: 'good', Poor: 'fair', Bad: 'poor', VeryBad: 'critical', Down: 'critical' };
    await rt.setNetworkQualityListener((q) => {
        if (!q) return;
        const worst = [q.uplink, q.downlink].map((l) => NET[l] || 'good').sort((a, b) => ['good', 'fair', 'poor', 'critical'].indexOf(b) - ['good', 'fair', 'poor', 'critical'].indexOf(a))[0];
        const why = NET[q.uplink] !== 'good' ? 'upload speed' : NET[q.downlink] !== 'good' ? 'download speed' : '';
        showQuality(worst, why, q.uplink === 'Unknown' && q.downlink === 'Unknown');
        recordQuality({ q: worst, up: q.uplink, down: q.downlink });
    });
    await rt.setStateListener((st) => {
        if (!live || live.ended || !st) return;
        const s = st.connectionState;
        if (live.diag.events.length < 30) live.diag.events.push([Math.round((performance.now() - live.diag.t0) / 1000), s]);
        if (s === 'Generating') return markGenerating(true);
        markGenerating(false);
        if (st.reason && st.reason.kind === 'failure') {
            finish('The stream stopped: ' + ((st.reason.error && st.reason.error.message) || 'connection lost') + '.', true);
        } else if (s === 'Idle' && live.everStarted) {
            finish('The video service ended the stream.');
        } else if (s !== 'Idle') {
            setStatus(s === 'Disconnecting' ? 'ENDING' : 'CONNECTING', 'wait');
        }
    });
    rt.setRemoteMediaTracksListener((tracks) => { if (tracks && tracks.videoTrack) showOutput(tracks.videoTrack); });

    const local = await rt.createLocalCameraStream({
        position: X.CameraPosition.front, useMicrophone: false, preset: X.RealtimeCameraPreset.resolution1280x704,
    });
    live.xmax.local = local;
    const camTrack = local.videoTrack && local.videoTrack.mediaStreamTrack;
    if (camTrack) {
        live.camTrack = camTrack;
        ui.self.srcObject = new MediaStream([camTrack]);
        ui.self.classList.remove('hidden');
    }

    const ext = file.type === 'image/png' ? 'png' : file.type === 'image/webp' ? 'webp' : 'jpg';
    const stored = await client.createStorageService().uploadImage({ data: file, fileName: `face.${ext}`, contentType: file.type });
    live.xmax.reference = stored.url;

    live.everStarted = true;
    const remote = await rt.startGeneration({
        localStream: local,
        context: new X.RealtimeContext({ prompt: promptFor('xmax'), referencePath: stored.url }),
    });
    if (remote && remote.videoTrack && remote.videoTrack.mediaStreamTrack) showOutput(remote.videoTrack.mediaStreamTrack);
    if (rt.currentState && rt.currentState.connectionState === 'Generating') markGenerating(true);
}

/* ------------------------------------------------------------------- finish */

async function finish(reason, isError = false) {
    if (!live || live.ended) return;
    markGenerating(false);   // close the running interval before marking the session ended
    live.ended = true;
    clearInterval(live.ticker);
    clearInterval(live.clockTimer);

    const seconds = usedSeconds();
    const diag = diagPayload();
    const ending = live;
    stopRecording(true);
    await leaveCall();
    try { ending.rt && ending.rt.disconnect(); } catch (_) { /* already closed */ }
    try { ending.xmax && ending.xmax.rt.close(); } catch (_) { /* already closed */ }
    if (ending.stream) ending.stream.getTracks().forEach((t) => t.stop());
    if (ending.camTrack) try { ending.camTrack.stop(); } catch (_) { /* gone */ }
    if (ending.mic) ending.mic.getTracks().forEach((t) => t.stop());

    show(ui.quality, false);
    ui.out.srcObject = null;
    ui.self.srcObject = null;
    ui.self.classList.add('hidden');
    ui.placeholder.classList.remove('hidden');
    show(ui.bar, false);
    show(ui.restyle, false);
    show(ui.clock, false, 'block');
    setStatus('ENDED', 'done');
    setChip(ui.chipCam, 'Ready', 'ok');
    ui.form.classList.remove('pointer-events-none', 'opacity-60');
    resetForm();

    const { data } = await api({ action: 'end', job: ending.job, seconds, diag }, true).catch(() => ({ data: {} }));
    say((reason ? reason + ' ' : '') + (data.message || 'Session ended.'), isError && data.status === 'failed' ? 'error' : 'success');
    const again = document.createElement('button');
    again.type = 'button';
    again.className = 'ml-2 font-medium text-signal underline';
    again.textContent = 'Refresh';
    again.onclick = () => location.reload();
    ui.msg.append(' ', again);
    if (live === ending) live = null;
    refreshLiveTools();
}

function resetForm() {
    ui.go.disabled = !ui.form.querySelector('input[name="engine"]');
    ui.go.textContent = ui.go.disabled ? 'Unavailable' : 'Start live session';
}

/* ------------------------------------------------------------- live controls */

ui.end.addEventListener('click', () => finish(''));
ui.full.addEventListener('click', () => {
    if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
    else if (ui.stage.requestFullscreen) ui.stage.requestFullscreen().catch(() => {});
});

ui.cam.addEventListener('click', () => {
    if (!live || !live.camTrack) return;
    live.camTrack.enabled = !live.camTrack.enabled;
    ui.cam.setAttribute('aria-pressed', String(live.camTrack.enabled));
    ui.cam.textContent = live.camTrack.enabled ? 'Camera on' : 'Camera off';
    setChip(ui.chipCam, live.camTrack.enabled ? 'Live' : 'Paused', live.camTrack.enabled ? 'ok' : 'warn');
});

ui.mic.addEventListener('click', async () => {
    if (!live) return;
    if (!live.mic) {
        try {
            live.mic = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true }, video: false });
        } catch (_) {
            return say('Microphone access was blocked. Allow it in your browser\'s address bar to talk in the group call.', 'error');
        }
        if (live.call) await live.call.setInputDevicesAsync({ audioSource: live.mic.getAudioTracks()[0] }).catch(() => {});
    }
    const on = ui.mic.getAttribute('aria-pressed') !== 'true';
    live.mic.getAudioTracks().forEach((t) => { t.enabled = on; });
    if (live.call) live.call.setLocalAudio(on);
    ui.mic.setAttribute('aria-pressed', String(on));
    ui.mic.textContent = on ? 'Mic on' : 'Mic off';
});

ui.restyle.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (!live) return;
    const text = new FormData(ui.restyle).get('prompt').toString().trim();
    try {
        if (live.provider === 'xmax' && live.xmax) {
            const X = await loadSdk(XMAX_SDK);
            await live.xmax.rt.startGeneration({
                localStream: live.xmax.local,
                context: new X.RealtimeContext({ prompt: promptFor('xmax', text), referencePath: live.xmax.reference }),
            });
        } else if (live.rt) {
            // set() replaces the whole state, so resend the photo with the new look.
            await live.rt.set({ prompt: promptFor('decart', text), image: live.file, enhance: true });
        }
        say('Style updated.', 'success');
    } catch (e) {
        say('Couldn\'t update the style: ' + ((e && e.message) || 'unknown error'), 'error');
    }
});

/** Enable the Telegram and group-call buttons that need a live stream. */
function refreshLiveTools() {
    const on = !!(live && !live.ended);
    ui.tgRec.disabled = !on || !!rec;
    ui.callStart.disabled = !on || root.dataset.call !== '1';
    if (!on) {
        ui.callLink.value = '';
        ui.callCopy.disabled = true;
        ui.callCount.textContent = '';
    }
}

/* --------------------------------------------------------- Telegram clips */

let tg = { available: false, linked: false, link: null, poll: null };
let rec = null;     // { recorder, feed, chunks, started, timer }
let clip = null;    // { blob, mime, seconds, url }

function renderTelegram() {
    if (!tg.available) {
        ui.tgConnect.disabled = true;
        ui.tgConnect.textContent = 'Telegram unavailable';
        return;
    }
    ui.tgConnect.disabled = false;
    ui.tgConnect.textContent = tg.linked ? 'Disconnect' : 'Connect Telegram';
    ui.tgState.classList.remove('hidden');
    ui.tgState.textContent = tg.linked ? 'Connected — clips go to your Telegram chat with our bot.' : 'Not connected yet.';
    ui.tgSend.disabled = !clip || !tg.linked;
}

async function loadTelegram() {
    const { data } = await api({ action: 'tg_status' }).catch(() => ({ data: {} }));
    tg = { ...tg, available: !!data.available, linked: !!data.linked, link: data.link || null };
    renderTelegram();
}

ui.tgConnect.addEventListener('click', async () => {
    if (tg.linked) {
        const { data } = await api({ action: 'tg_unlink' }).catch(() => ({ data: {} }));
        tg.linked = false;
        tg.link = data.link || null;
        return renderTelegram();
    }
    if (!tg.link) return say('Telegram isn\'t available right now.', 'error');
    window.open(tg.link, '_blank', 'noopener');
    ui.tgState.textContent = 'Press Start in the Telegram chat that opened, then come back here…';
    clearInterval(tg.poll);
    let tries = 0;
    tg.poll = setInterval(async () => {
        tries += 1;
        const { data } = await api({ action: 'tg_check' }).catch(() => ({ data: {} }));
        if (data.linked) {
            clearInterval(tg.poll);
            tg.linked = true;
            renderTelegram();
            say('Telegram connected.', 'success');
        } else if (tries >= 40) {
            clearInterval(tg.poll);
            ui.tgState.textContent = 'Didn\'t see you press Start. Click Connect Telegram to try again.';
        }
    }, 3000);
});

function recorderType() {
    const types = ['video/mp4;codecs=avc1.42E01E', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'];
    return types.find((t) => window.MediaRecorder && MediaRecorder.isTypeSupported(t)) || '';
}

ui.tgRec.addEventListener('click', () => {
    if (!live || rec) return;
    discardClip();
    const mimeType = recorderType();
    if (!mimeType) return say('This browser can\'t record video. Try Chrome or Edge.', 'error');
    const feed = outboundStream(true);
    const recorder = new MediaRecorder(feed.stream, { mimeType, videoBitsPerSecond: 1_200_000 });
    rec = { recorder, feed, chunks: [], started: performance.now(), mime: mimeType.startsWith('video/mp4') ? 'video/mp4' : 'video/webm' };
    recorder.ondataavailable = (e) => { if (e.data && e.data.size) rec.chunks.push(e.data); };
    recorder.onstop = () => {
        if (!rec) return;
        const seconds = Math.min(CLIP_MAX_SEC, Math.round((performance.now() - rec.started) / 1000));
        const blob = new Blob(rec.chunks, { type: rec.mime });
        rec.feed.stop();
        const keep = !rec.discard && blob.size > 0;
        rec = null;
        if (keep) {
            clip = { blob, mime: blob.type, seconds, url: URL.createObjectURL(blob) };
            ui.tgPreview.src = clip.url;
            ui.tgPreview.classList.remove('hidden');
        }
        ui.tgStop.disabled = true;
        ui.tgDiscard.disabled = !clip;
        ui.tgRec.textContent = 'Record video note';
        refreshLiveTools();
        renderTelegram();
    };
    recorder.start(1000);
    rec.timer = setTimeout(() => stopRecording(false), CLIP_MAX_SEC * 1000);
    ui.tgRec.disabled = true;
    ui.tgRec.textContent = 'Recording…';
    ui.tgStop.disabled = false;
});

function stopRecording(discard) {
    if (!rec) return;
    clearTimeout(rec.timer);
    rec.discard = discard;
    try { rec.recorder.stop(); } catch (_) { rec.feed.stop(); rec = null; }
}
ui.tgStop.addEventListener('click', () => stopRecording(false));

function discardClip() {
    if (clip) URL.revokeObjectURL(clip.url);
    clip = null;
    ui.tgPreview.removeAttribute('src');
    ui.tgPreview.classList.add('hidden');
    ui.tgDiscard.disabled = true;
    renderTelegram();
}
ui.tgDiscard.addEventListener('click', discardClip);

ui.tgSend.addEventListener('click', async () => {
    if (!clip) return;
    const job = (live && live.job) || Number(root.dataset.job || 0);
    ui.tgSend.disabled = true;
    ui.tgSend.textContent = 'Sending…';
    const fields = { action: 'tg_send', job, seconds: clip.seconds, clip: new File([clip.blob], clip.mime === 'video/mp4' ? 'clip.mp4' : 'clip.webm', { type: clip.mime }) };
    if (clip.mime === 'video/mp4') fields.note = 1;
    const { data } = await api(fields).catch(() => ({ data: { error: 'Couldn\'t reach the server.' } }));
    ui.tgSend.textContent = 'Send to Telegram';
    if (data.ok) {
        say(data.message || 'Sent to your Telegram.', 'success');
        discardClip();
    } else {
        say(data.error || 'Couldn\'t send the clip.', 'error');
        if (/Connect Telegram/.test(data.error || '')) { tg.linked = false; loadTelegram(); }
        renderTelegram();
    }
});

/* -------------------------------------------------------------- group call */

ui.callStart.addEventListener('click', async () => {
    if (!live || live.call) return;
    ui.callStart.disabled = true;
    ui.callStart.textContent = 'Creating…';
    const { data } = await api({ action: 'call_open', job: live.job }).catch(() => ({ data: {} }));
    if (!data.ok) {
        ui.callStart.textContent = 'Create call link';
        refreshLiveTools();
        return say(data.error || 'Couldn\'t create a call link.', 'error');
    }
    try {
        const Daily = (await loadSdk(DAILY_SDK)).default;
        live.callFeed = outboundStream(false);
        const call = Daily.createCallObject({
            videoSource: live.callFeed.track,
            audioSource: live.mic ? live.mic.getAudioTracks()[0] : false,
            subscribeToTracksAutomatically: true,
        });
        live.call = call;
        const update = () => renderGuests(call);
        call.on('participant-joined', update).on('participant-left', update).on('participant-updated', update)
            .on('left-meeting', () => { if (live) { live.call = null; } renderGuests(null); })
            .on('error', (e) => say('Group call error: ' + ((e && e.errorMsg) || 'unknown'), 'error'));
        await call.join({ url: data.url, token: data.token, userName: root.dataset.name || 'Host' });
        call.setLocalAudio(!!(live.mic && ui.mic.getAttribute('aria-pressed') === 'true'));
        ui.callLink.value = data.url;
        ui.callCopy.disabled = false;
        ui.callStart.textContent = 'Call live';
        update();
    } catch (e) {
        await leaveCall();
        ui.callStart.textContent = 'Create call link';
        refreshLiveTools();
        say('Couldn\'t start the group call' + (e && e.message ? ` (${e.message})` : '') + '.', 'error');
    }
});

ui.callCopy.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(ui.callLink.value); ui.callCopy.textContent = 'Copied'; }
    catch (_) { ui.callLink.select(); }
    setTimeout(() => { ui.callCopy.textContent = 'Copy'; }, 1500);
});

function renderGuests(call) {
    ui.callTiles.innerHTML = '';
    if (!call) { ui.callCount.textContent = ''; return; }
    const guests = Object.values(call.participants()).filter((p) => !p.local);
    ui.callCount.textContent = guests.length ? `${guests.length} guest${guests.length === 1 ? '' : 's'} in the call` : 'Waiting for guests to join…';
    for (const p of guests) {
        const tile = document.createElement('div');
        tile.className = 'relative aspect-video overflow-hidden rounded-lg border border-border bg-ink';
        const track = p.tracks && p.tracks.video && p.tracks.video.persistentTrack;
        if (track && p.tracks.video.state === 'playable') {
            const v = document.createElement('video');
            v.autoplay = true; v.playsInline = true; v.muted = true;
            v.className = 'h-full w-full object-cover';
            v.srcObject = new MediaStream([track]);
            tile.append(v);
        }
        const audio = p.tracks && p.tracks.audio && p.tracks.audio.persistentTrack;
        if (audio && p.tracks.audio.state === 'playable') {
            const a = document.createElement('audio');
            a.autoplay = true;
            a.srcObject = new MediaStream([audio]);
            tile.append(a);
        }
        const name = document.createElement('span');
        name.className = 'absolute bottom-1 left-1 rounded bg-ink/80 px-1.5 py-0.5 text-[11px] text-paper';
        name.textContent = p.user_name || 'Guest';
        tile.append(name);
        ui.callTiles.append(tile);
    }
}

async function leaveCall() {
    if (!live) return;
    const call = live.call;
    live.call = null;
    if (call) {
        try { await call.leave(); } catch (_) { /* gone */ }
        try { await call.destroy(); } catch (_) { /* gone */ }
    }
    if (live.callFeed) { live.callFeed.stop(); live.callFeed = null; }
    ui.callStart.textContent = 'Create call link';
    renderGuests(null);
}

/* --------------------------------------------------------- tab closed mid-stream */

window.addEventListener('pagehide', () => {
    if (!live || live.ended) return;
    live.ended = true;
    const body = new FormData();
    body.append('_token', root.dataset.csrf);
    body.append('format', 'json');
    body.append('action', 'end');
    body.append('job', String(live.job));
    body.append('seconds', String(usedSeconds()));
    body.append('diag', diagPayload());
    navigator.sendBeacon(root.dataset.api, body);
    try { live.rt && live.rt.disconnect(); } catch (_) { /* closing anyway */ }
    try { live.xmax && live.xmax.rt.close(); } catch (_) { /* closing anyway */ }
    try { live.call && live.call.leave(); } catch (_) { /* closing anyway */ }
});

/* -------------------------------------------------------------------- boot */

if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    ui.go.textContent = 'Camera not available here';
    setChip(ui.chipCam, 'Not available', 'bad');
    say('This browser can\'t use a camera on this page. Livestream needs a secure (https) connection and a modern browser.', 'error');
} else {
    resetForm();
    if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'camera' }).then((p) => {
            const paint = () => setChip(ui.chipCam, p.state === 'granted' ? 'Ready' : p.state === 'denied' ? 'Blocked' : 'Needs permission',
                p.state === 'granted' ? 'ok' : p.state === 'denied' ? 'bad' : 'warn');
            paint();
            p.onchange = paint;
        }).catch(() => {});
    }
    // Warm the default engine's SDK so starting is quick.
    const first = ui.form.querySelector('input[name="engine"]:checked');
    if (first) loadSdk(first.dataset.provider === 'xmax' ? XMAX_SDK : DECART_SDK).catch(() => {});
}
loadTelegram();
refreshLiveTools();
root.dataset.ready = 'sdk';
