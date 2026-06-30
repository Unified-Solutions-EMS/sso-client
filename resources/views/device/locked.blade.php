<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Device authorization required</title>
    <style>
        :root { --brand: #1176bc; --brand-dark: #0e5e97; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #f3f4f6; color: #111827;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        .card {
            width: 100%; max-width: 30rem; margin: 1.5rem;
            background: #fff; border: 1px solid #e5e7eb; border-radius: 0.875rem;
            box-shadow: 0 1px 3px rgba(0,0,0,.08); padding: 2rem; text-align: center;
        }
        .icon { width: 3rem; height: 3rem; margin: 0 auto 1rem; color: var(--brand); }
        h1 { font-size: 1.125rem; font-weight: 600; margin: 0 0 .5rem; }
        p { font-size: .875rem; color: #6b7280; margin: 0 0 1rem; line-height: 1.5; }
        .panel { display: none; }
        .panel.active { display: block; }
        .spinner {
            width: 1.5rem; height: 1.5rem; margin: 0 auto 1rem; border: 3px solid #e5e7eb;
            border-top-color: var(--brand); border-radius: 50%; animation: spin .8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        input[type=text] {
            width: 100%; padding: .625rem .75rem; font-size: .95rem; letter-spacing: .08em;
            text-align: center; text-transform: uppercase; border: 1px solid #d1d5db; border-radius: .5rem;
        }
        input[type=text]:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 1px var(--brand); }
        button {
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            width: 100%; margin-top: .75rem; padding: .625rem 1rem; font-size: .875rem; font-weight: 500;
            color: #fff; background: var(--brand); border: 0; border-radius: .5rem; cursor: pointer;
        }
        button:hover { background: var(--brand-dark); }
        button.secondary { color: var(--brand); background: #fff; border: 1px solid #d1d5db; }
        button.secondary:hover { background: #f9fafb; }
        button:disabled { opacity: .5; cursor: default; }
        .error { color: #b91c1c; font-size: .8rem; margin-top: .5rem; min-height: 1rem; }
        .muted { font-size: .75rem; color: #9ca3af; margin-top: 1rem; }
    </style>
</head>
<body>
    <div class="card">
        <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                  d="M9 12.75L11.25 15 15 9.75M21 12c0 1.2-.26 2.34-.73 3.37-1.2 2.62-3.6 4.57-6.52 5.18a1.5 1.5 0 01-.5 0c-2.92-.61-5.32-2.56-6.52-5.18A8.96 8.96 0 015 12V6.75c0-.41.25-.78.63-.93l6-2.4a1.5 1.5 0 011.12 0l6 2.4c.38.15.63.52.63.93V12z" />
        </svg>

        <div id="checking" class="panel active">
            <div class="spinner"></div>
            <h1>Checking this device</h1>
            <p>Confirming that {{ $appName }} is authorized to run on this computer.</p>
        </div>

        <div id="verifying" class="panel">
            <div class="spinner"></div>
            <h1>Authorizing this device</h1>
            <p>One moment while we verify this device with the {{ $companyName ?? 'your agency' }} security policy.</p>
        </div>

        <div id="needs-extension" class="panel">
            <h1>Device extension required</h1>
            <p>{{ $appName }} is locked to approved devices for {{ $companyName ?? 'your agency' }}. Install the Unified device extension, then reload this page.</p>
            <button class="secondary" onclick="location.reload()">I've installed it, retry</button>
        </div>

        <div id="authorize" class="panel">
            <h1>Authorize this device</h1>
            <p>This device is not yet approved. Enter an authorization code from your administrator, or approve it yourself if you are an agency admin.</p>
            <input id="code" type="text" maxlength="19" placeholder="XXXX-XXXX-XXXX" autocomplete="off">
            <div id="authorize-error" class="error"></div>
            <button id="redeem">Authorize with code</button>
            <button id="admin" class="secondary">I'm an admin, approve this device</button>
        </div>

        <div id="denied" class="panel">
            <h1>Not authorized on this device</h1>
            <p>This device is not approved to open {{ $appName }} for {{ $companyName ?? 'your agency' }}. Contact your administrator, or authorize it below.</p>
            <button class="secondary" onclick="showPanel('authorize')">Authorize this device</button>
        </div>

        <p class="muted">{{ $appName }} &middot; device security</p>
    </div>

    <script>
        const CFG = {!! json_encode([
            'challengeUrl' => $challengeUrl,
            'verifyUrl' => $verifyUrl,
            'registerUrl' => $registerUrl,
            'intendedUrl' => $intendedUrl,
        ], JSON_UNESCAPED_SLASHES) !!};
        const CSRF = document.querySelector('meta[name=csrf-token]').content;
        const EXT_TIMEOUT = 2000;

        function showPanel(id) {
            document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
            document.getElementById(id).classList.add('active');
        }

        // Request/response bridge to the extension content script via postMessage.
        function askExtension(type, payload = {}, timeout = EXT_TIMEOUT) {
            return new Promise((resolve) => {
                const nonce = Math.random().toString(36).slice(2);
                const timer = setTimeout(() => { window.removeEventListener('message', onMsg); resolve(null); }, timeout);
                function onMsg(event) {
                    if (event.source !== window) return;
                    const data = event.data || {};
                    if (data.source !== 'unified-device-ext' || data.nonce !== nonce) return;
                    clearTimeout(timer);
                    window.removeEventListener('message', onMsg);
                    resolve(data);
                }
                window.addEventListener('message', onMsg);
                window.postMessage({ source: 'unified-device', type, nonce, ...payload }, window.location.origin);
            });
        }

        async function post(url, body) {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify(body || {}),
            });
            return { ok: res.ok, status: res.status, data: await res.json().catch(() => ({})) };
        }

        async function runHandshake(keyId) {
            showPanel('verifying');
            const challenge = await post(CFG.challengeUrl, { key_id: keyId });
            if (!challenge.ok) { showPanel('denied'); return; }

            const signed = await askExtension('sign', { challenge: challenge.data.challenge, keyId });
            if (!signed || !signed.signature) { showPanel('denied'); return; }

            const verify = await post(CFG.verifyUrl, {
                key_id: signed.keyId || keyId,
                challenge: challenge.data.challenge,
                signature: signed.signature,
            });

            if (verify.ok && verify.data.verified) {
                window.location.replace(CFG.intendedUrl);
            } else {
                showPanel('denied');
            }
        }

        async function register(mode, code) {
            const errEl = document.getElementById('authorize-error');
            errEl.textContent = '';

            const created = await askExtension('register', { code: code || null });
            if (!created || !created.publicKey) {
                errEl.textContent = 'The device extension did not respond. Make sure it is installed and enabled.';
                return;
            }

            const body = { mode, key_id: created.keyId, public_key: created.publicKey };
            if (mode === 'code') { body.code = code; }

            const res = await post(CFG.registerUrl, body);
            if (res.status === 201) {
                runHandshake(created.keyId);
            } else {
                errEl.textContent = mode === 'code'
                    ? 'That code is invalid or expired. Ask your administrator for a new one.'
                    : 'We could not authorize this device. You may not be an admin for this agency.';
            }
        }

        document.getElementById('redeem').addEventListener('click', () => {
            const code = document.getElementById('code').value.trim();
            if (code) { register('code', code); }
        });
        document.getElementById('admin').addEventListener('click', () => register('admin'));

        (async function init() {
            const status = await askExtension('status');
            if (!status) { showPanel('needs-extension'); return; }
            if (status.registered && status.keyId) { runHandshake(status.keyId); return; }
            showPanel('authorize');
        })();
    </script>
</body>
</html>
