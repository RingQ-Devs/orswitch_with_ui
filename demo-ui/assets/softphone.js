// Minimal OpenRings WebRTC phone: the same WebSocket protocol the switch's own
// dialer uses (/api/ws: phone.start, phone.dial, phone.answer ...). See
// docs/API.md section 10. No libraries.
(() => {
  const $ = (id) => document.getElementById(id);
  const wsUrl = (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + window.WS_PATH;
  let ws, pc, stream, remoteSet = false, pending = [], calls = {}, muted = false;

  const send = (msg) => { if (ws && ws.readyState === 1) ws.send(JSON.stringify(msg)); };
  const setReg = (ok, text) => { const b = $('reg'); b.textContent = text; b.className = 'badge ' + (ok ? 'green' : 'red'); };

  async function start() {
    try {
      // Microphone first: the browser asks for permission once.
      stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
    } catch (e) { setReg(false, 'microphone blocked'); $('call').textContent = String(e); return; }

    ws = new WebSocket(wsUrl);
    ws.onclose = () => setReg(false, 'disconnected (is the proxy set up? are you signed in?)');
    ws.onmessage = (e) => onMessage(JSON.parse(e.data));
    ws.onopen = connectAudio;
  }

  async function connectAudio() {
    pc = new RTCPeerConnection({ bundlePolicy: 'max-bundle' });
    const sender = pc.addTransceiver('audio', { direction: 'sendrecv' }).sender;
    await sender.replaceTrack(stream.getAudioTracks()[0]);
    pc.ontrack = (e) => { $('audio').srcObject = e.streams[0] || new MediaStream([e.track]); };
    pc.onicecandidate = (e) => { if (e.candidate) send({ t: 'phone.ice', candidate: e.candidate.toJSON() }); };
    pc.onconnectionstatechange = () => { $('media').textContent = 'audio: ' + pc.connectionState; };
    await pc.setLocalDescription(await pc.createOffer());
    send({ t: 'phone.start', sdp: pc.localDescription, gen: 1 });   // registers the extension
  }

  async function onMessage(m) {
    switch (m.t) {
      case 'phone.sdp':                       // the switch's answer
        await pc.setRemoteDescription(m.sdp);
        remoteSet = true;
        for (const c of pending) await pc.addIceCandidate(c).catch(() => {});
        pending = [];
        break;
      case 'phone.ice':
        if (remoteSet) pc.addIceCandidate(m.candidate).catch(() => {}); else pending.push(m.candidate);
        break;
      case 'phone.reg':
        setReg(m.ok, m.ok ? 'registered' : 'not registered: ' + (m.reason || ''));
        break;
      case 'phone.call':
        if (m.call.state === 'ended') delete calls[m.call.id]; else calls[m.call.id] = m.call;
        render();
        break;
      case 'error':
        $('call').textContent = 'Error: ' + m.error;
        break;
    }
  }

  const current = () => Object.values(calls)[0];

  function render() {
    const c = current();
    const incoming = c && c.direction === 'in' && c.state === 'ringing';
    $('answer').hidden = !incoming;
    $('hangup').hidden = !c;
    $('mute').hidden = !c || c.state !== 'answered';
    $('dial').hidden = !!c;
    $('call').textContent = c
      ? (c.direction === 'in' ? 'Incoming from ' : 'Calling ') + (c.name && c.name !== c.number ? c.name + ' ' : '') + c.number + ' · ' + c.state
      : '';
  }

  $('dial').onclick = () => { const n = $('number').value.trim(); if (n) send({ t: 'phone.dial', number: n }); };
  $('answer').onclick = () => { const c = current(); if (c) send({ t: 'phone.answer', call: c.id }); };
  $('hangup').onclick = () => { const c = current(); if (c) send({ t: 'phone.hangup', call: c.id }); };
  $('mute').onclick = () => {
    muted = !muted;
    stream.getAudioTracks().forEach((t) => { t.enabled = !muted; });
    send({ t: 'phone.mute', on: muted });
    $('mute').textContent = muted ? 'Unmute' : 'Mute';
  };
  document.querySelectorAll('.pad button').forEach((b) => {
    b.onclick = () => {
      const c = current();
      if (c && c.state === 'answered') send({ t: 'phone.dtmf', call: c.id, digits: b.dataset.k });  // IVR keys
      else $('number').value += b.dataset.k;
    };
  });
  window.addEventListener('beforeunload', () => send({ t: 'phone.stop' }));

  start();
})();
