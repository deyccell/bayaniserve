<?php
session_name('bayaniserve_resident_session');
session_start();
if (!isset($_SESSION['chat_session_id'])) {
    $_SESSION['chat_session_id'] = bin2hex(random_bytes(16));
}

require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

$stations  = $pdo->query("SELECT id, barangay_name FROM health_stations ORDER BY barangay_name")->fetchAll();
$medicines = $pdo->query("SELECT DISTINCT m.id, m.name FROM medicines m INNER JOIN inventory i ON i.medicine_id = m.id ORDER BY m.name")->fetchAll();
// Only show announcements posted by barangay admins/BHWs — NOT superadmin (city health) posts
$announcements = $pdo->query(
    "SELECT a.title, a.message, a.created_at, a.target_station_id, h.barangay_name, ad.full_name
     FROM announcements a
     JOIN admins ad ON a.posted_by = ad.id
     LEFT JOIN health_stations h ON a.target_station_id = h.id
     WHERE ad.role != 'super_admin'
       AND a.target_station_id IS NOT NULL
     ORDER BY a.created_at DESC LIMIT 50"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BayaniServe — Health Medicine Availability</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
body { background: #f0f4f8; min-height: 100vh; display: flex; flex-direction: column; }

.header { background: linear-gradient(135deg, #185FA5 0%, #0c447c 100%); color: #fff; padding: 16px 20px; display: flex; align-items: center; gap: 12px; }
.header-icon { font-size: 28px; }
.header-title { font-size: 18px; font-weight: 700; }
.header-sub { font-size: 12px; opacity: .8; margin-top: 2px; }

.tabs { display: flex; background: #fff; border-bottom: 2px solid #e5e7eb; overflow-x: auto; }
.tab { flex: 1; min-width: 80px; padding: 12px 8px; text-align: center; font-size: 13px; font-weight: 500; color: #6b7280; cursor: pointer; border: none; background: none; border-bottom: 3px solid transparent; margin-bottom: -2px; white-space: nowrap; transition: color .15s, border-color .15s; }
.tab.active { color: #185FA5; border-bottom-color: #185FA5; }
.tab:hover { color: #185FA5; }

.panel { display: none; flex: 1; overflow-y: auto; padding: 16px; }
.panel.active { display: flex; flex-direction: column; gap: 14px; }

.check-card { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.check-card h2 { font-size: 16px; font-weight: 600; color: #1e3a5f; margin-bottom: 4px; }
.check-card p { font-size: 13px; color: #6b7280; margin-bottom: 16px; }
.form-row { display: flex; flex-direction: column; gap: 10px; }
.form-group label { font-size: 12px; font-weight: 600; color: #374151; display: block; margin-bottom: 4px; }
select { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 14px; background: #fff; outline: none; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; }
select:focus { border-color: #185FA5; box-shadow: 0 0 0 3px rgba(24,95,165,.15); }
.btn-check { width: 100%; padding: 12px; background: #185FA5; color: #fff; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; transition: background .15s; }
.btn-check:hover { background: #0c447c; }
.btn-check:disabled { background: #93c5fd; cursor: not-allowed; }

.result-card { border-radius: 12px; padding: 14px 16px; margin-bottom: 10px; border: 1px solid #e5e7eb; }
.result-card.in-stock    { background: #f0fdf4; border-color: #86efac; }
.result-card.low-stock   { background: #fffbeb; border-color: #fcd34d; }
.result-card.out-of-stock{ background: #fef2f2; border-color: #fca5a5; }
.result-station { font-size: 13px; font-weight: 700; color: #1f2937; margin-bottom: 4px; }
.result-med { font-size: 15px; font-weight: 600; }
.result-qty { font-size: 13px; margin-top: 4px; }
.badge { display: inline-block; padding: 2px 10px; border-radius: 99px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; }
.badge-in  { background: #dcfce7; color: #15803d; }
.badge-low { background: #fef3c7; color: #92400e; }
.badge-out { background: #fee2e2; color: #991b1b; }
.empty-state { text-align: center; color: #9ca3af; font-size: 14px; padding: 40px 0; }
.loading     { text-align: center; color: #185FA5; font-size: 14px; padding: 20px 0; }
.result-actions { display: flex; gap: 8px; margin-top: 10px; }
.btn-action { flex: 1; padding: 8px; border: none; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition: opacity .15s; }
.btn-reserve { background: #185FA5; color: #fff; }
.btn-request { background: #6b7280; color: #fff; }
.btn-action:hover { opacity: .85; }

.chat-wrap { display: flex; flex-direction: column; }
.msgs { overflow-y: auto; padding: 4px 0; display: flex; flex-direction: column; gap: 10px; min-height: 300px; max-height: 60vh; }
.msg { max-width: 82%; padding: 10px 14px; border-radius: 16px; font-size: 14px; line-height: 1.5; }
.bot  { align-self: flex-start; background: #fff; border: 1px solid #e5e7eb; border-bottom-left-radius: 4px; }
.user { align-self: flex-end; background: #185FA5; color: #fff; border-bottom-right-radius: 4px; }
.action-note { align-self: flex-start; background: #e0f2fe; border-left: 3px solid #0ea5e9; padding: 8px 12px; border-radius: 8px; font-size: 12px; color: #0369a1; max-width: 82%; }
.pills { display: flex; gap: 6px; flex-wrap: wrap; margin: 8px 0; }
.pill { font-size: 12px; padding: 5px 12px; border: 1px solid #d1d5db; border-radius: 99px; cursor: pointer; background: #fff; color: #374151; transition: background .12s; }
.pill:hover { background: #f3f4f6; }
.inputrow { display: flex; gap: 8px; padding: 12px 0 0; border-top: 1px solid #e5e7eb; margin-top: 8px; }
.inputrow input { flex: 1; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 99px; font-size: 14px; outline: none; }
.inputrow input:focus { border-color: #185FA5; }
.inputrow button { padding: 10px 18px; background: #185FA5; color: #fff; border: none; border-radius: 99px; font-size: 14px; cursor: pointer; font-weight: 600; }
.inputrow button:disabled { background: #93c5fd; cursor: not-allowed; }

.announce-card { background: #fff; border-radius: 12px; padding: 16px; border-left: 4px solid #185FA5; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.announce-title { font-size: 15px; font-weight: 700; color: #1e3a5f; margin-bottom: 6px; }
.announce-body  { font-size: 14px; color: #374151; line-height: 1.6; }
.announce-meta  { font-size: 11px; color: #9ca3af; margin-top: 8px; }

.toast { position: fixed; bottom: 80px; left: 50%; transform: translateX(-50%); background: #1f2937; color: #fff; padding: 10px 20px; border-radius: 99px; font-size: 13px; font-weight: 500; opacity: 0; transition: opacity .3s; pointer-events: none; white-space: nowrap; z-index: 999; }
.toast.show { opacity: 1; }
</style>
</head>
<body>

<div class="header">
    <div class="header-icon" style="display: flex; align-items: center; justify-content: center;">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="header-svg"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M12 8v8"/><path d="M8 12h8"/></svg>
    </div>
    <div>
        <div class="header-title">BayaniServe</div>
        <div class="header-sub">Barangay Health Medicine Availability</div>
    </div>
</div>

<div class="tabs">
    <button class="tab active" onclick="switchTab('check', this)">Check Stock</button>
    <button class="tab"        onclick="switchTab('chat', this)">AI Assistant</button>
    <button class="tab"        onclick="switchTab('announcements', this)">Announcements</button>
</div>

<div class="panel active" id="tab-check">
    <div class="check-card">
        <h2>Check Medicine Availability</h2>
        <p>Piliin ang barangay at gamot para makita ang kasalukuyang stock.</p>
        <div class="form-row">
            <div class="form-group">
                <label>Barangay / Health Station</label>
                <select id="sel-station">
                    <option value="">— Pumili ng Barangay —</option>
                    <?php foreach ($stations as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['barangay_name']) ?></option>
                    <?php endforeach; ?>
                    <option value="all">Lahat ng Barangay</option>
                </select>
            </div>
            <div class="form-group">
                <label>Pangalan ng Gamot</label>
                <select id="sel-medicine">
                    <option value="">— Pumili ng Gamot —</option>
                    <?php foreach ($medicines as $m): ?>
                        <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                    <?php endforeach; ?>
                    <option value="all">Lahat ng Gamot</option>
                </select>
            </div>
            <button class="btn-check" onclick="checkStock()" id="btn-check">Tingnan ang Stock</button>
        </div>
    </div>
    <div id="results"></div>
</div>

<div class="panel" id="tab-chat">
    <div class="check-card chat-wrap">
        <div class="pills">
            <span class="pill" onclick="quickFill('Ano ang available na gamot sa Hilamonan?')">Check stock</span>
            <span class="pill" onclick="quickFill('Gusto ko mag-reserve ng Paracetamol sa Camugao')">Reserve</span>
            <span class="pill" onclick="quickFill('Wala bang Amoxicillin sa Inapoy, pwede mag-request?')">Request</span>
        </div>
        <div class="msgs" id="msgs">
            <div class="msg bot">Kumusta! Pwede kayo mag-check sang stock, mag-reserve, ukon mag-request sang tambal diri. Ano ang kinahanglan ninyo?</div>
        </div>
        <div class="inputrow">
            <input type="text" id="chat-input" placeholder="I-type ang mensahe..." onkeydown="if(event.key==='Enter')sendChat()">
            <button id="chat-send-btn" onclick="sendChat()">Send</button>
        </div>
    </div>
</div>

<div class="panel" id="tab-announcements">
    <div style="margin-bottom:15px; display:flex; flex-direction:column; gap:6px;">
        <label style="font-size:12px; font-weight:600; color:#374151;">Piliin ang iyong Barangay para sa mga lokal na anunsyo:</label>
        <select id="sel-announce-station" onchange="filterAnnouncements()" style="padding:10px 12px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; background:#fff; outline:none; width:100%;">
            <option value="">— Piliin ang iyong Barangay —</option>
            <?php foreach ($stations as $s): ?>
                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['barangay_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div id="announce-list">
        <?php if (empty($announcements)): ?>
            <div class="empty-state">Walang announcements sa ngayon.</div>
        <?php else: ?>
            <?php foreach ($announcements as $ann): ?>
            <div class="announce-card" data-station-id="<?= $ann['target_station_id'] ?: 'global' ?>" style="margin-bottom:12px;">
                <div class="announce-title"><?= htmlspecialchars($ann['title']) ?></div>
                <div class="announce-body"><?= nl2br(htmlspecialchars($ann['message'])) ?></div>
                <div class="announce-meta">
                    Posted by <?= htmlspecialchars($ann['full_name']) ?>
                    <?php if ($ann['barangay_name']): ?>
                        • Barangay: <?= htmlspecialchars($ann['barangay_name']) ?>
                    <?php endif; ?>
                    • <?= date('M j, Y g:i A', strtotime($ann['created_at'])) ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
const SESSION_ID = <?= json_encode($_SESSION['chat_session_id']) ?>;

function switchTab(name, el) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    el.classList.add('active');
}

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}

async function checkStock() {
    const stationId  = document.getElementById('sel-station').value;
    const medicineId = document.getElementById('sel-medicine').value;
    const resultsEl  = document.getElementById('results');
    const btn        = document.getElementById('btn-check');

    if (!stationId)  { showToast('Pumili muna ng Barangay.'); return; }
    if (!medicineId) { showToast('Pumili muna ng Gamot.'); return; }

    btn.disabled = true;
    btn.textContent = 'Hinahanap...';
    resultsEl.innerHTML = '<div class="loading">Hinahanap ang stock...</div>';

    try {
        const res  = await fetch('../resident/stock_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ station_id: stationId, medicine_id: medicineId })
        });
        const data = await res.json();
        renderResults(data.results || []);
    } catch(e) {
        resultsEl.innerHTML = '<div class="empty-state">Hindi makonekta sa server. Subukan ulit.</div>';
    } finally {
        btn.disabled = false;
        btn.textContent = 'Tingnan ang Stock';
    }
}

function renderResults(rows) {
    const el = document.getElementById('results');
    if (!rows.length) {
        el.innerHTML = '<div class="empty-state">Walang nahanap na stock para sa napiling gamot/barangay.</div>';
        return;
    }
    el.innerHTML = rows.map(r => {
        const sc = r.status === 'in_stock' ? 'in-stock' : r.status === 'low_stock' ? 'low-stock' : 'out-of-stock';
        const bc = r.status === 'in_stock' ? 'badge-in' : r.status === 'low_stock' ? 'badge-low' : 'badge-out';
        const bl = r.status === 'in_stock' ? 'Available' : r.status === 'low_stock' ? 'Mababa ang Stock' : 'Wala nang Stock';
        const qt = r.quantity > 0 ? `${r.quantity} units na available` : 'Wala nang stock';
        const btn = r.quantity > 0
            ? `<button class="btn-action btn-reserve" onclick="goChat('Gusto ko mag-reserve ng ${r.medicine_name} sa ${r.barangay_name}')">I-Reserve</button>`
            : `<button class="btn-action btn-request" onclick="goChat('Wala bang ${r.medicine_name} sa ${r.barangay_name}? Pwede mag-request?')">Mag-Request</button>`;
        return `<div class="result-card ${sc}">
            <div class="result-station">${r.barangay_name}</div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;">
                <div class="result-med">${r.medicine_name}</div>
                <span class="badge ${bc}">${bl}</span>
            </div>
            <div class="result-qty">${qt}</div>
            <div class="result-actions">${btn}</div>
        </div>`;
    }).join('');
}

function goChat(msg) {
    document.querySelectorAll('.tab').forEach((t,i)   => t.classList.toggle('active', i===1));
    document.querySelectorAll('.panel').forEach((p,i) => p.classList.toggle('active', i===1));
    document.getElementById('chat-input').value = msg;
    document.getElementById('chat-input').focus();
}

function quickFill(text) {
    document.getElementById('chat-input').value = text;
    document.getElementById('chat-input').focus();
}

async function sendChat() {
    const input = document.getElementById('chat-input');
    const sendBtn = document.getElementById('chat-send-btn');
    const text  = input.value.trim();
    if (!text) return;
    
    // Lock inputs while processing chunks
    input.value = '';
    input.disabled = true;
    sendBtn.disabled = true;

    const wrap   = document.getElementById('msgs');
    const uDiv   = document.createElement('div');
    uDiv.className = 'msg user';
    uDiv.textContent = text;
    wrap.appendChild(uDiv);

    // Create the blank bot response message bubble
    const typing = document.createElement('div');
    typing.className = 'msg bot';
    typing.textContent = '';
    wrap.appendChild(typing);
    wrap.scrollTop = wrap.scrollHeight;

    try {
        // SSE connection requires using urlencoded components to seamlessly read chunks 
        const res = await fetch('../resident/chat_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `message=${encodeURIComponent(text)}&session_id=${encodeURIComponent(SESSION_ID)}`
        });

        if (!res.ok) throw new Error("HTTP error");

        const reader = res.body.getReader();
        const decoder = new TextDecoder("utf-8");
        let partialLine = "";

        while (true) {
            const { value, done } = await reader.read();
            if (done) break;

            const chunk = decoder.decode(value, { stream: true });
            const lines = (partialLine + chunk).split("\n");
            partialLine = lines.pop(); // Hold onto incomplete data lines

            for (const line of lines) {
                const cleanedLine = line.trim();
                if (cleanedLine.startsWith("data: ")) {
                    try {
                        const data = JSON.parse(cleanedLine.substring(6));
                        
                        // Stream character/token data incrementally
                        if (data.reply) {
                            typing.textContent += data.reply;
                            wrap.scrollTop = wrap.scrollHeight;
                        }
                        
                        // Render reservation metadata notes at the tail end
                        if (data.done && data.action_taken) {
                            const note = document.createElement('div');
                            note.className = 'action-note';
                            note.textContent = '✓ ' + data.action_taken;
                            wrap.appendChild(note);
                            wrap.scrollTop = wrap.scrollHeight;
                        }
                    } catch (jsonErr) {
                        // Suppress background JSON parsing artifacts
                    }
                }
            }
        }
    } catch(e) {
        typing.textContent = 'Hindi makakonekta sa AI model. Pakisigurado na tumatakbo ang Ollama service engine.';
    } finally {
        input.disabled = false;
        sendBtn.disabled = false;
        input.focus();
        wrap.scrollTop = wrap.scrollHeight;
    }
}

function filterAnnouncements() {
    const selectedStation = document.getElementById('sel-announce-station').value;
    const cards = document.querySelectorAll('#announce-list .announce-card');
    let visibleCount = 0;
    
    cards.forEach(card => {
        const cardStation = card.getAttribute('data-station-id');
        if (!selectedStation) {
            card.style.display = 'none';
        } else if (cardStation === selectedStation) {
            card.style.display = 'block';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });
    
    const emptyState = document.getElementById('announce-empty-state');
    if (!selectedStation) {
        if (!emptyState) {
            const div = document.createElement('div');
            div.id = 'announce-empty-state';
            div.className = 'empty-state';
            div.textContent = 'Piliin ang iyong barangay para makita ang mga anunsyo.';
            document.getElementById('announce-list').appendChild(div);
        } else {
            emptyState.style.display = 'block';
        }
    } else if (visibleCount === 0) {
        if (!emptyState) {
            const div = document.createElement('div');
            div.id = 'announce-empty-state';
            div.className = 'empty-state';
            div.textContent = 'Walang anunsyo para sa napiling barangay.';
            document.getElementById('announce-list').appendChild(div);
        } else {
            emptyState.textContent = 'Walang anunsyo para sa napiling barangay.';
            emptyState.style.display = 'block';
        }
    } else {
        if (emptyState) {
            emptyState.style.display = 'none';
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    filterAnnouncements();
});
</script>
</body>
</html>