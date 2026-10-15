/* SleekAI — Livestream page. Runs the Decart realtime session in the browser.
 *
 * Order matters for money: the camera is requested BEFORE anything is charged, so a
 * denied permission costs nothing. Once charged, every exit path — End button,
 * Decart disconnect, error, time limit, closing the tab — reports the seconds used
 * so unused time can be refunded.
 */

// Pinned: a new SDK version must be tested before the page picks it up.
const SDK_URL = 'https://cdn.jsdelivr.net/npm/@decartai/sdk@0.2.8/+esm';
// Asks for a photoreal likeness that follows the camera closely; the reference photo carries the identity.
const DEFAULT_LOOK = 'Replace me with the person in the reference photo: same face, hair and skin tone, photorealistic, '
    + 'natural lighting and skin texture. Follow my exact head movements, facial expressions, lip movements and gestures.';
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
    quality: $('liveQuality'),
};

/* ---------------------------------------------------------- diagnostics */
// Decart's SDK reports connection quality every few seconds. We show the verdict
// on the video, and send a compact summary to the server with each tick so the
// cause of lag/flicker (network latency, upload bandwidth, loss, the computer's
// CPU, a weak camera feed) can be read afterwards from storage/live-diag.log.
const QUALITY_TEXT = { good: 'Good', fair: 'Fair', poor: 'Poor', critical: 'Very poor' };
const QUALITY_DOT = { good: 'bg-reel', fair: 'bg-signal', poor: 'bg-tally', critical: 'bg-tally' };
const LIMIT_TEXT = { bandwidth: 'upload speed', latency: 'network delay', loss: 'packet loss', stall: 'stalls', cpu: 'device CPU' };

function showQuality(q) {
    if (!ui.quality || !q) return;
    const why = q.quality !== 'good' && LIMIT_TEXT[q.limitingFactor] ? ` · ${LIMIT_TEXT[q.limitingFactor]}` : '';
    ui.quality.innerHTML = '';
    const dot = document.createElement('span');
    dot.className = `h-1.5 w-1.5 rounded-full ${QUALITY_DOT[q.quality] || 'bg-muted'}`;
    const text = document.createElement('span');
    text.textContent = q.warmingUp ? 'Connection: measuring…' : `Connection: ${QUALITY_TEXT[q.quality] || q.quality}${why}`;
    ui.quality.append(dot, text);
    ui.quality.classList.remove('hidden');
    ui.quality.classList.add('flex');
}

const r1 = (n) => (typeof n === 'number' && isFinite(n) ? Math.round(n * 10) / 10 : null);

function recordQuality(q) {
    if (!live || !q || !q.metrics) return;
    const m = q.metrics;
    live.diag.samples.push({
        t: Math.round((performance.now() - live.diag.t0) / 1000), q: q.quality, lim: q.limitingFactor, warm: q.warmingUp,
        rtt: r1(m.rttMs), g2g: r1(m.g2gMs), fps: r1(m.fps), loss: r1(m.packetLoss), up: r1(m.availableUpstreamKbps),
        jit: r1(m.upstreamJitterMs), drop: r1(m.g2gDropRatio),
    });
    if (m.ttffMs != null) live.diag.ttffMs = r1(m.ttffMs);
    if (live.diag.samples.length > 40) live.diag.samples.shift();   // keep the latest ~2 minutes
}

function recordStats(s) {
    if (!live || !s || !s.video) return;
    const v = s.video;
    live.diag.video = { w: v.frameWidth, h: v.frameHeight, fps: r1(v.framesPerSecond), kbps: Math.round((v.bitrate || 0) / 1000),
        freezes: v.freezeCount, freezeSec: r1(v.totalFreezesDuration), lost: v.packetsLost, dropped: v.framesDropped,
        jbMs: r1(v.avgJitterBufferMs), decodeMs: r1(v.avgDecodeTimeMs), decoder: v.decoderImplementation };
}

function diagPayload() {
    if (!live || !live.diag) return '';
    const s = JSON.stringify(live.diag);
    return s.length > 7500 ? JSON.stringify({ ...live.diag, samples: live.diag.samples.slice(-15) }) : s;
}

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
    const model = sdk.models.realtime(root.dataset.model || 'lucy-2.5');
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
    const camTrack = stream.getVideoTracks()[0];
    const cam = camTrack && camTrack.getSettings ? camTrack.getSettings() : {};
    const photoSize = await new Promise((res) => {
        const img = new Image();
        img.onload = () => { res({ w: img.naturalWidth, h: img.naturalHeight }); URL.revokeObjectURL(img.src); };
        img.onerror = () => res({ w: 0, h: 0 });
        img.src = URL.createObjectURL(file);
    });

    // 2. Charge and get a token.
    ui.go.textContent = 'Starting session…';
    const { status, data } = await api({ action: 'start', minutes: minutes.value, consent: 1 }).catch(() => ({ status: 0, data: {} }));
    if (!data.ok) {
        stopCamera(stream);
        resetForm();
        return say(data.error || 'Couldn\'t reach SleekAI. You haven\'t been charged.', 'error', !!data.buy || status === 402);
    }

    live = { job: data.job, rt: null, stream, maxSeconds: data.maxSeconds, generatingSince: 0, generatedMs: 0, ended: false,
        diag: { t0: performance.now(), model: data.model, speed: data.speed || 'standard', ua: navigator.userAgent,
                cores: navigator.hardwareConcurrency || null, mem: navigator.deviceMemory || null,
                net: navigator.connection ? { type: navigator.connection.effectiveType, down: navigator.connection.downlink, rtt: navigator.connection.rtt } : null,
                cam: { w: cam.width, h: cam.height, fps: r1(cam.frameRate), label: camTrack ? camTrack.label.slice(0, 60) : '' },
                photo: { ...photoSize, kb: Math.round(file.size / 1024), type: file.type },
                prompt: (ui.prompt.value.trim() || 'default').slice(0, 120), samples: [], video: null, ttffMs: null, events: [] } };
    root.dataset.job = String(data.job);
    setStatus('CONNECTING', 'wait');
    ui.clock.classList.remove('hidden');
    ui.clock.textContent = `${fmt(live.maxSeconds)} left`;

    // 3. Connect straight to Decart with the short-lived token.
    try {
        const client = sdk.createDecartClient({ apiKey: data.token });
        const rt = await client.realtime.connect(stream, {
            model: sdk.models.realtime(data.model),
            ...(data.speed === 'fast' ? { speed: 'fast' } : {}),
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
        rt.on('connectionQuality', (q) => { showQuality(q); recordQuality(q); });
        rt.on('stats', recordStats);
        rt.on('connectionChange', (state) => { if (live && live.diag.events.length < 30) live.diag.events.push([Math.round((performance.now() - live.diag.t0) / 1000), state]); });
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
        live.ticks = (live.ticks || 0) + 1;
        const diag = live.ticks % 6 === 0 ? diagPayload() : '';   // every ~30 s; the full summary also goes with 'end'
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

/* ------------------------------------------------------------------- finish */

async function finish(reason, isError = false) {
    if (!live || live.ended) return;
    live.ended = true;
    if (live.generatingSince) { live.generatedMs += performance.now() - live.generatingSince; live.generatingSince = 0; }
    clearInterval(live.ticker);
    clearInterval(live.clockTimer);

    const seconds = usedSeconds();
    const diag = diagPayload();
    try { live.rt && live.rt.disconnect(); } catch (_) { /* already closed */ }
    if (ui.quality) { ui.quality.classList.add('hidden'); ui.quality.classList.remove('flex'); }
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

    const { data } = await api({ action: 'end', job: live.job, seconds, diag }, true).catch(() => ({ data: {} }));
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
    body.append('diag', diagPayload());
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
