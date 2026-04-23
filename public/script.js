let username = '';
let ws;

// ===================== TIMER =====================
const TIMER_RADIUS = 22;
const TIMER_CIRCUMFERENCE = 2 * Math.PI * TIMER_RADIUS; // ~138.23

let timerInterval = null;
let timerRemaining = 0;
let timerTotal = 0;

function startTimer(seconds) {
    stopTimer();

    timerRemaining = seconds;
    timerTotal = seconds;

    const wrapper = document.getElementById('timer-wrapper');
    const arc = document.getElementById('timer-arc');
    const number = document.getElementById('timer-number');

    // Init arc
    arc.style.strokeDasharray = TIMER_CIRCUMFERENCE;
    arc.style.strokeDashoffset = 0;
    arc.classList.remove('urgent');
    number.classList.remove('urgent');

    wrapper.style.display = 'flex';
    updateTimerUI();

    timerInterval = setInterval(() => {
        timerRemaining--;
        updateTimerUI();
        if (timerRemaining <= 0) stopTimer();
    }, 1000);
}

function updateTimerUI() {
    const arc = document.getElementById('timer-arc');
    const number = document.getElementById('timer-number');

    // Avancement : de 0 (plein) à circumference (vide)
    const progress = timerRemaining / timerTotal;
    const offset = TIMER_CIRCUMFERENCE * (1 - progress);

    arc.style.strokeDashoffset = offset;
    number.textContent = timerRemaining;

    // Urgence sous 5s
    const urgent = timerRemaining <= 5;
    arc.classList.toggle('urgent', urgent);
    number.classList.toggle('urgent', urgent);
}

function stopTimer() {
    if (timerInterval) {
        clearInterval(timerInterval);
        timerInterval = null;
    }
}

function hideTimer() {
    stopTimer();
    document.getElementById('timer-wrapper').style.display = 'none';
}

// ===================== HELPERS =====================
function showScreen(id) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    document.getElementById(id).classList.add('active');
}

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}

function addChatMsg(from, message, isServer = false) {
    const div = document.createElement('div');
    div.className = 'chat-msg' + (isServer ? ' server' : '');
    div.innerHTML = `<strong>${from}:</strong> ${message}`;
    const chat = document.getElementById('chat-messages');
    chat.appendChild(div);
    chat.scrollTop = chat.scrollHeight;
}

// ===================== WELCOME =====================
document.getElementById('btn-enter').onclick = () => {
    const val = document.getElementById('input-username').value.trim();
    if (!val) { showToast('Entre un pseudo !'); return; }
    username = val;
    connectWS();
};
document.getElementById('input-username').onkeydown = (e) => {
    if (e.key === 'Enter') document.getElementById('btn-enter').click();
};

// ===================== WEBSOCKET =====================
function connectWS() {
    // En prod (Docker) : passe par Nginx /ws
    // En dev local : ws://localhost:8080
    const wsProto = location.protocol === 'https:' ? 'wss' : 'ws';
    const wsUrl = `${wsProto}://${location.host}/ws`;
    ws = new WebSocket(wsUrl);

    ws.onopen = () => {
        ws.send(JSON.stringify({ type: 'get_lobby', username }));
        document.getElementById('lobby-username').textContent = username;
        document.getElementById('lobby-avatar').textContent = username[0].toUpperCase();
        showScreen('screen-lobby');
    };

    ws.onmessage = (event) => {
        const data = JSON.parse(event.data);
        switch (data.type) {
            case 'lobby': renderLobby(data.rooms); break;
            case 'room_joined':
                document.getElementById('game-room-name').textContent = data.room;
                showScreen('screen-game');
                break;
            case 'users': renderPlayers(data.users); break;
            case 'message': addChatMsg(data.from, data.message, data.from === 'Serveur'); break;
            case 'error': showToast(data.message); break;
            case 'game_update': handleGameUpdate(data); break;
        }
    };

    ws.onerror = () => showToast('Erreur de connexion au serveur.');
    ws.onclose = () => showToast('Déconnecté du serveur.');
}

// ===================== LOBBY =====================
function renderLobby(rooms) {
    const grid = document.getElementById('rooms-grid');
    grid.innerHTML = '';

    if (!rooms || rooms.length === 0) {
        grid.innerHTML = '<div class="empty-rooms">Aucune room pour l\'instant...<br>Crées-en une !</div>';
        return;
    }

    rooms.forEach(room => {
        const phaseLabel = { waiting: 'En attente', writing: 'Écriture', voting: 'Vote', result: 'Résultats' }[room.phase] || room.phase;
        const phaseClass = room.phase === 'waiting' ? 'badge-waiting' : 'badge-phase';
        const card = document.createElement('div');
        card.className = 'room-card';
        card.innerHTML = `
      <div class="room-name">${room.name}</div>
      <div class="room-meta">
        <span class="badge badge-players">${room.players} joueur(s)</span>
        <span class="badge ${phaseClass}">${phaseLabel}</span>
      </div>
    `;
        card.onclick = () => ws.send(JSON.stringify({ type: 'join_room', roomName: room.name }));
        grid.appendChild(card);
    });
}

document.getElementById('btn-create-room').onclick = () => {
    const name = document.getElementById('input-roomname').value.trim();
    if (!name) { showToast('Donne un nom à ta room !'); return; }
    ws.send(JSON.stringify({ type: 'create_room', roomName: name }));
    document.getElementById('input-roomname').value = '';
};
document.getElementById('input-roomname').onkeydown = (e) => {
    if (e.key === 'Enter') document.getElementById('btn-create-room').click();
};

// ===================== PLAYERS =====================
function renderPlayers(users) {
    document.getElementById('players-list').innerHTML = users.map(u => `
    <div class="player-row"><div class="player-dot"></div>${u}</div>
  `).join('');
}

// ===================== GAME UPDATE =====================
function handleGameUpdate(data) {
    const phasePill = document.getElementById('phase-pill');
    const roundLabel = document.getElementById('round-label');
    const memeContainer = document.getElementById('meme-container');
    const memeImg = document.getElementById('meme-img');
    const captionForm = document.getElementById('caption-form');
    const captionsGrid = document.getElementById('captions-grid');
    const waitingMsg = document.getElementById('waiting-msg');
    const resultCard = document.getElementById('result-card');

    waitingMsg.style.display = 'none';
    resultCard.style.display = 'none';
    captionsGrid.innerHTML = '';

    if (data.round) roundLabel.textContent = `Round ${data.round}`;

    // ---- WRITING ----
    if (data.phase === 'writing') {
        phasePill.className = 'phase-pill phase-writing';
        phasePill.textContent = 'Écriture';

        memeImg.src = data.image;
        memeContainer.style.display = 'block';
        captionForm.style.display = 'flex';

        document.getElementById('caption-input').value = '';
        document.getElementById('btn-send-caption').disabled = false;
        document.getElementById('caption-input').disabled = false;
        const sent = captionForm.querySelector('.sent-msg');
        if (sent) sent.remove();

        // Timer 30s
        document.getElementById('timer-label').textContent = 'ÉCRITURE';
        startTimer(30);
    }

    // ---- VOTING ----
    if (data.phase === 'voting') {
        phasePill.className = 'phase-pill phase-voting';
        phasePill.textContent = 'Vote';

        memeImg.src = data.image;
        memeContainer.style.display = 'block';
        captionForm.style.display = 'none';

        if (data.captions) {
            for (const [rid, text] of Object.entries(data.captions)) {
                const card = document.createElement('div');
                card.className = 'caption-card votable';
                card.textContent = text;
                card.dataset.rid = rid;
                card.onclick = () => {
                    ws.send(JSON.stringify({ type: 'vote', voteFor: rid }));
                    card.classList.replace('votable', 'voted');
                    document.querySelectorAll('.caption-card.votable').forEach(c => {
                        c.classList.remove('votable');
                        c.onclick = null;
                    });
                };
                captionsGrid.appendChild(card);
            }
        }

        // Timer 20s
        document.getElementById('timer-label').textContent = 'VOTE';
        startTimer(20);
    }

    // ---- RESULT ----
    if (data.phase === 'result') {
        phasePill.className = 'phase-pill phase-result';
        phasePill.textContent = 'Résultats';

        memeContainer.style.display = 'none';
        captionForm.style.display = 'none';
        resultCard.style.display = 'block';

        document.getElementById('result-winner').textContent = data.winner;
        document.getElementById('result-caption').textContent = `"${data.caption}"`;

        document.getElementById('scores-list').innerHTML = Object.entries(data.scores || {})
            .sort(([, a], [, b]) => b - a)
            .map(([name, pts], i) => `
        <div class="score-row">
          <span class="score-name">${i === 0 ? '👑 ' : ''}${name}</span>
          <span class="score-pts">${pts} pt${pts > 1 ? 's' : ''}</span>
        </div>
      `).join('');

        // Pas de timer sur l'écran résultat
        hideTimer();
    }
}

// ===================== CHAT =====================
document.getElementById('btn-chat-send').onclick = sendChat;
document.getElementById('chat-input').onkeydown = (e) => { if (e.key === 'Enter') sendChat(); };
function sendChat() {
    const msg = document.getElementById('chat-input').value.trim();
    if (!msg || !ws || ws.readyState !== WebSocket.OPEN) return;
    ws.send(JSON.stringify({ type: 'message', message: msg }));
    document.getElementById('chat-input').value = '';
}

// ===================== CAPTION =====================
document.getElementById('btn-send-caption').onclick = sendCaption;
document.getElementById('caption-input').onkeydown = (e) => { if (e.key === 'Enter') sendCaption(); };
function sendCaption() {
    const val = document.getElementById('caption-input').value.trim();
    if (!val || !ws || ws.readyState !== WebSocket.OPEN) return;
    ws.send(JSON.stringify({ type: 'caption', caption: val }));
    document.getElementById('caption-input').value = '';
    document.getElementById('btn-send-caption').disabled = true;
    document.getElementById('caption-input').disabled = true;
    const form = document.getElementById('caption-form');
    if (!form.querySelector('.sent-msg')) {
        const msg = document.createElement('p');
        msg.className = 'sent-msg caption-hint';
        msg.textContent = 'Légende envoyée !';
        form.appendChild(msg);
    }
}