<?php
session_name('bayaniserve_resident_session');
session_start();

if (!isset($_SESSION['chat_session_id'])) {
    $_SESSION['chat_session_id'] = bin2hex(random_bytes(16));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BayaniServe — Health Assistant</title>

<style>
* { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, sans-serif; }

body {
    background: #f1efe8;
    height: 100vh;
    display: flex;
    flex-direction: column;
}

.top {
    background: #fff;
    border-bottom: 1px solid #e0ded5;
    padding: 14px 18px;
}

.top-title { font-size: 16px; font-weight: 600; color: #2c2c2a; }
.top-sub { font-size: 12px; color: #5f5e5a; }

.msgs {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.msg {
    max-width: 80%;
    padding: 10px 14px;
    border-radius: 14px;
    font-size: 14px;
    line-height: 1.5;
}

.bot {
    align-self: flex-start;
    background: #fff;
    border: 1px solid #e0ded5;
    border-bottom-left-radius: 4px;
}

.user {
    align-self: flex-end;
    background: #185FA5;
    color: #fff;
    border-bottom-right-radius: 4px;
}

.action {
    align-self: flex-start;
    background: #e6f1fb;
    border-left: 3px solid #185FA5;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 12px;
    color: #0c447c;
    max-width: 80%;
}

.inputrow {
    display: flex;
    gap: 8px;
    padding: 12px;
    background: #fff;
    border-top: 1px solid #e0ded5;
}

.inputrow input {
    flex: 1;
    padding: 10px 14px;
    border: 1px solid #b4b2a9;
    border-radius: 20px;
    font-size: 14px;
    outline: none;
}

.inputrow button {
    padding: 10px 18px;
    background: #185FA5;
    color: #fff;
    border: none;
    border-radius: 20px;
    font-size: 14px;
    cursor: pointer;
}

.inputrow button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.pills {
    padding: 10px 16px 0;
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.pill {
    font-size: 12px;
    padding: 5px 12px;
    border: 1px solid #b4b2a9;
    border-radius: 16px;
    cursor: pointer;
    background: #fff;
    color: #5f5e5a;
}
</style>
</head>

<body>

<div class="top">
    <div class="top-title">BayaniServe</div>
    <div class="top-sub">AI health assistant — multilingual support</div>
</div>

<div class="pills">
    <div class="pill" onclick="quickFill('Check available Paracetamol in Hilamonan')">Check stock</div>
    <div class="pill" onclick="quickFill('I want to reserve Amoxicillin for [name] in [barangay]')">Reserve medicine</div>
    <div class="pill" onclick="quickFill('No stock of [medicine], can I request it?')">Request medicine</div>
</div>

<div class="msgs" id="msgs">
    <div class="msg bot">
        Hello! You can check stock, reserve medicine, or request medicine here.
    </div>
</div>

<div class="inputrow">
    <input type="text" id="input" placeholder="Type your message..."
        onkeydown="if(event.key==='Enter')send()">
    <button id="sendBtn" onclick="send()">Send</button>
</div>

<script>
const sessionId = <?= json_encode($_SESSION['chat_session_id']) ?>;

function quickFill(text) {
    document.getElementById('input').value = text;
}

function addMsg(text, cls) {
    const wrap = document.getElementById('msgs');
    const div = document.createElement('div');
    div.className = 'msg ' + cls;
    div.textContent = text;
    wrap.appendChild(div);
    wrap.scrollTo({ top: wrap.scrollHeight, behavior: 'smooth' });
}

function addAction(text) {
    const wrap = document.getElementById('msgs');
    const div = document.createElement('div');
    div.className = 'action';
    div.textContent = text;
    wrap.appendChild(div);
    wrap.scrollTo({ top: wrap.scrollHeight, behavior: 'smooth' });
}

function addTyping() {
    const wrap = document.getElementById('msgs');
    const div = document.createElement('div');
    div.className = 'msg bot';
    div.id = "typing";
    div.textContent = "Typing...";
    wrap.appendChild(div);
    wrap.scrollTo({ top: wrap.scrollHeight, behavior: 'smooth' });
}

function removeTyping() {
    const el = document.getElementById('typing');
    if (el) el.remove();
}

async function send() {
    const input = document.getElementById('input');
    const btn = document.getElementById('sendBtn');

    const text = input.value.trim();
    if (!text) return;

    input.value = '';
    btn.disabled = true;

    addMsg(text, 'user');
    addTyping();

    try {
        const res = await fetch('chat_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                message: text,
                session_id: sessionId
            })
        });

        const raw = await res.text();
        const data = JSON.parse(raw);

        removeTyping();
        addMsg(data.reply, 'bot');

        if (data.action_taken) {
            addAction(data.action_taken);
        }

    } catch (e) {
        removeTyping();
        addMsg('Server error. Please try again.', 'bot');
    }

    btn.disabled = false;
}
</script>

</body>
</html>