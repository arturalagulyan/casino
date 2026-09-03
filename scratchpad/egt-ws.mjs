// Probe the EGT GamePlatform WS handshake for one session.
// usage: node egt-ws.mjs <sessionToken> <gin>
const token = process.argv[2] || '1e4ec19b39abe699dfdce32c4ee0bde215693cf4';
const gin = process.argv[3] || '546';
const url = process.env.WS || 'ws://localhost:2087/slots';

const ws = new WebSocket(url);
const send = (o) => { const f = ':::' + JSON.stringify(o); console.log('→', f.slice(0, 200)); ws.send(f); };

let step = 0;
ws.onopen = () => {
  console.log('open', url);
  send({ command: 'login', sessionId: token, gameIdentificationNumber: gin, messageId: 'm1' });
};
ws.onmessage = (e) => {
  const raw = typeof e.data === 'string' ? e.data : e.data.toString();
  const body = raw.startsWith(':::') ? raw.slice(3) : raw;
  let msg; try { msg = JSON.parse(body); } catch { console.log('← (non-json)', raw.slice(0,120)); return; }
  console.log('←', msg.command || msg.responseEvent, '  keys:', Object.keys(msg).join(','));
  if (msg.command === 'login') {
    const complex = msg.complex || {};
    const entries = Object.entries(complex).map(([k, v]) => `${k} → gin ${v?.[0]?.gameIdentificationNumber}`);
    console.log('   multigame:', msg.multigame, ' complex entries:', entries.length);
    console.log('   ' + entries.slice(0, 12).join('\n   '));
    console.log('   contains requested gin', gin, '?', JSON.stringify(complex).includes('"gameIdentificationNumber":' + gin) || JSON.stringify(complex).includes('"gameIdentificationNumber":"' + gin + '"'));
    step = 1;
    send({ command: 'settings', sessionId: token, gameIdentificationNumber: gin, messageId: 'm2' });
  } else if (msg.command === 'settings' && step === 1) {
    step = 2;
    send({ command: 'subscribe', sessionId: token, gameIdentificationNumber: gin, messageId: 'm3' });
  } else if (msg.command === 'subscribe') {
    console.log('   subscribe currentState.state:', msg.complex?.currentState?.state);
    setTimeout(() => { ws.close(); }, 200);
  }
};
ws.onerror = (e) => console.log('error', e.message || e);
ws.onclose = () => { console.log('closed'); process.exit(0); };
setTimeout(() => { console.log('timeout'); process.exit(1); }, 8000);
