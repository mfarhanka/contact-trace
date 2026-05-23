const path = require('path');

require('dotenv').config({ path: path.resolve(__dirname, '..', '.env') });

const express = require('express');
const QRCode = require('qrcode');
const { Client, LocalAuth } = require('whatsapp-web.js');

const app = express();
const host = (process.env.WHATSAPP_BRIDGE_HOST || '127.0.0.1').trim() || '127.0.0.1';
const port = Number.parseInt(process.env.WHATSAPP_BRIDGE_PORT || '3001', 10) || 3001;
const bridgeToken = (process.env.WHATSAPP_BRIDGE_TOKEN || process.env.BRIDGE_TOKEN || '').trim();

const state = {
  state: 'starting',
  connected: false,
  authenticated: false,
  qrDataUrl: '',
  clientId: '',
  lastError: '',
  updatedAt: new Date().toISOString(),
};

function setState(patch) {
  Object.assign(state, patch, { updatedAt: new Date().toISOString() });
}

function currentStatus() {
  return {
    state: state.state,
    connected: state.connected,
    authenticated: state.authenticated,
    qrAvailable: state.qrDataUrl !== '',
    qrDataUrl: state.qrDataUrl,
    clientId: state.clientId,
    lastError: state.lastError,
    updatedAt: state.updatedAt,
  };
}

function normalizePhone(phone) {
  const digits = String(phone || '').replace(/\D+/g, '');

  if (digits === '') {
    return '';
  }

  if (digits.startsWith('00')) {
    return digits.slice(2);
  }

  if (digits.startsWith('0')) {
    return `6${digits}`;
  }

  return digits;
}

function responseOk(result) {
  return { ok: true, result };
}

function responseError(error) {
  return { ok: false, error };
}

function requestToken(req) {
  const headerToken = req.get('x-bridge-token');

  if (headerToken) {
    return String(headerToken).trim();
  }

  if (typeof req.query.token === 'string') {
    return req.query.token.trim();
  }

  return '';
}

function requireBridgeToken(req, res, next) {
  if (bridgeToken === '') {
    res.status(503).json(responseError('Bridge token is missing. Set WHATSAPP_BRIDGE_TOKEN in .env or environment variables.'));
    return;
  }

  if (requestToken(req) !== bridgeToken) {
    res.status(401).json(responseError('Bridge token is invalid.'));
    return;
  }

  next();
}

function renderDashboard() {
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WhatsApp Bridge</title>
  <style>
    :root {
      color-scheme: light;
      --bg: #f6efe5;
      --panel: #fffaf5;
      --ink: #1f2a1f;
      --muted: #6c7468;
      --line: #d9cfbf;
      --accent: #1d6f42;
      --accent-soft: #ddefdf;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
      background: radial-gradient(circle at top, #fff6eb 0%, var(--bg) 55%, #efe2d0 100%);
      color: var(--ink);
    }
    main {
      max-width: 960px;
      margin: 0 auto;
      padding: 24px;
    }
    .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 24px;
      box-shadow: 0 18px 40px rgba(76, 62, 43, 0.08);
    }
    h1 { margin-top: 0; margin-bottom: 8px; }
    p { color: var(--muted); }
    .grid {
      display: grid;
      gap: 20px;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    }
    .card {
      border: 1px solid var(--line);
      border-radius: 16px;
      padding: 18px;
      background: rgba(255, 255, 255, 0.72);
    }
    label { display: block; font-weight: 600; margin-bottom: 8px; }
    input {
      width: 100%;
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 12px 14px;
      font: inherit;
      margin-bottom: 12px;
    }
    button {
      border: 0;
      border-radius: 999px;
      padding: 10px 18px;
      background: var(--accent);
      color: white;
      font: inherit;
      cursor: pointer;
    }
    .status {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 10px 14px;
      border-radius: 999px;
      background: var(--accent-soft);
      color: var(--accent);
      font-weight: 600;
    }
    .qr-box {
      min-height: 280px;
      border: 1px dashed var(--line);
      border-radius: 18px;
      display: grid;
      place-items: center;
      background: #fff;
      overflow: hidden;
    }
    .qr-box img {
      width: min(100%, 320px);
      height: auto;
      display: block;
    }
    dl {
      margin: 0;
      display: grid;
      grid-template-columns: 120px 1fr;
      gap: 10px 14px;
    }
    dt { font-weight: 700; }
    dd { margin: 0; color: var(--muted); word-break: break-word; }
    code {
      background: #f0eadf;
      border-radius: 8px;
      padding: 2px 6px;
    }
  </style>
</head>
<body>
  <main>
    <section class="panel">
      <h1>WhatsApp QR Dashboard</h1>
      <p>Keep this bridge running locally. Scan the QR code with WhatsApp on your phone, then Contact Trace can send the first message automatically after a Telegram lead is added.</p>
      <div class="grid">
        <div class="card">
          <label for="token">Bridge token</label>
          <input id="token" type="password" placeholder="Paste WHATSAPP_BRIDGE_TOKEN">
          <button id="refresh" type="button">Refresh status</button>
          <p>Admin already builds a dashboard URL with the token. If you opened this page directly, paste the same token here.</p>
        </div>
        <div class="card">
          <div class="status" id="state-pill">Starting</div>
          <dl>
            <dt>Connected</dt>
            <dd id="connected">No</dd>
            <dt>Client</dt>
            <dd id="client">Waiting</dd>
            <dt>Updated</dt>
            <dd id="updated">-</dd>
            <dt>Last error</dt>
            <dd id="last-error">-</dd>
          </dl>
        </div>
      </div>
      <div class="card" style="margin-top:20px;">
        <div class="qr-box" id="qr-box">
          <p id="qr-placeholder">Waiting for QR code...</p>
        </div>
      </div>
      <p style="margin-top:16px;">API endpoint: <code>/api/status</code> and <code>/api/send</code>. Both require the same bridge token.</p>
    </section>
  </main>
  <script>
    const tokenField = document.getElementById('token');
    const refreshButton = document.getElementById('refresh');
    const statePill = document.getElementById('state-pill');
    const connectedEl = document.getElementById('connected');
    const clientEl = document.getElementById('client');
    const updatedEl = document.getElementById('updated');
    const lastErrorEl = document.getElementById('last-error');
    const qrBox = document.getElementById('qr-box');
    const params = new URLSearchParams(window.location.search);

    if (params.get('token')) {
      tokenField.value = params.get('token');
    }

    function renderQr(dataUrl) {
      qrBox.innerHTML = dataUrl ? '<img src="' + dataUrl + '" alt="WhatsApp QR code">' : '<p id="qr-placeholder">No QR right now. If the bridge is connected, this is expected.</p>';
    }

    async function refreshStatus() {
      const token = tokenField.value.trim();
      const url = token ? '/api/status?token=' + encodeURIComponent(token) : '/api/status';

      try {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const payload = await response.json();

        if (!payload.ok) {
          throw new Error(payload.error || 'Unable to load bridge status.');
        }

        const result = payload.result || {};
        statePill.textContent = result.state || 'unknown';
        connectedEl.textContent = result.connected ? 'Yes' : 'No';
        clientEl.textContent = result.clientId || 'Waiting';
        updatedEl.textContent = result.updatedAt || '-';
        lastErrorEl.textContent = result.lastError || '-';
        renderQr(result.qrDataUrl || '');
      } catch (error) {
        statePill.textContent = 'error';
        lastErrorEl.textContent = error.message;
        renderQr('');
      }
    }

    refreshButton.addEventListener('click', refreshStatus);
    refreshStatus();
    window.setInterval(refreshStatus, 5000);
  </script>
</body>
</html>`;
}

app.use(express.json({ limit: '256kb' }));

app.get('/', (req, res) => {
  res.type('html').send(renderDashboard());
});

app.get('/health', (_req, res) => {
  res.json(responseOk({ status: 'ok' }));
});

app.get('/api/status', requireBridgeToken, (req, res) => {
  res.json(responseOk(currentStatus()));
});

const client = new Client({
  authStrategy: new LocalAuth({ dataPath: path.resolve(__dirname, '.wwebjs_auth') }),
  puppeteer: {
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  },
});

client.on('qr', async (qr) => {
  try {
    const qrDataUrl = await QRCode.toDataURL(qr, { margin: 1, width: 320 });
    setState({
      state: 'qr',
      connected: false,
      authenticated: false,
      qrDataUrl,
      lastError: '',
      clientId: '',
    });
  } catch (error) {
    setState({
      state: 'qr-error',
      connected: false,
      authenticated: false,
      qrDataUrl: '',
      lastError: error instanceof Error ? error.message : String(error),
      clientId: '',
    });
  }
});

client.on('authenticated', () => {
  setState({
    state: 'authenticated',
    authenticated: true,
    lastError: '',
  });
});

client.on('ready', () => {
  const clientId = client.info && client.info.wid ? client.info.wid.user : '';

  setState({
    state: 'ready',
    connected: true,
    authenticated: true,
    qrDataUrl: '',
    clientId,
    lastError: '',
  });
});

client.on('auth_failure', (message) => {
  setState({
    state: 'auth_failure',
    connected: false,
    authenticated: false,
    qrDataUrl: '',
    lastError: String(message || 'Authentication failed.'),
    clientId: '',
  });
});

client.on('disconnected', (reason) => {
  setState({
    state: 'disconnected',
    connected: false,
    authenticated: false,
    qrDataUrl: '',
    lastError: String(reason || 'WhatsApp disconnected.'),
    clientId: '',
  });
});

client.on('loading_screen', (percent, message) => {
  setState({
    state: `loading ${percent}%`,
    lastError: String(message || ''),
  });
});

app.post('/api/send', requireBridgeToken, async (req, res) => {
  if (!state.connected) {
    res.status(409).json(responseError('WhatsApp client is not ready. Open the QR dashboard and complete authentication first.'));
    return;
  }

  const phone = normalizePhone(req.body && req.body.phone);
  const text = String((req.body && req.body.text) || '').trim();

  if (phone === '') {
    res.status(400).json(responseError('Phone number is required.'));
    return;
  }

  if (text === '') {
    res.status(400).json(responseError('Message text is required.'));
    return;
  }

  try {
    const message = await client.sendMessage(`${phone}@c.us`, text);
    res.json(responseOk({
      to: phone,
      messageId: message && message.id ? message.id._serialized || '' : '',
    }));
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    setState({ lastError: errorMessage });
    res.status(500).json(responseError(errorMessage || 'Unable to send WhatsApp message.'));
  }
});

app.listen(port, host, () => {
  console.log(`WhatsApp bridge listening on http://${host}:${port}`);
});

client.initialize().catch((error) => {
  setState({
    state: 'init_error',
    connected: false,
    authenticated: false,
    qrDataUrl: '',
    lastError: error instanceof Error ? error.message : String(error),
  });
  console.error('WhatsApp bridge failed to initialize:', error);
});