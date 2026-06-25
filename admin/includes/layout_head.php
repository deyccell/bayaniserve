<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
.nav i {
  font-size: 15px;
  width: 20px;
  display: inline-flex;
  justify-content: center;
  align-items: center;
  margin-right: 6px;
  color: var(--text3);
  transition: color .12s;
}
.nav:hover i { color: var(--text1); }
.nav.active i { color: var(--blue); }

:root {
  --bg:       #F0EDE6;
  --surface:  #FFFFFF;
  --border:   #E2DDD6;
  --border2:  #EDE9E2;
  --text1:    #1C1C1A;
  --text2:    #5A5955;
  --text3:    #8A8884;
  --blue:     #185FA5;
  --blue-dk:  #0C4480;
  --blue-lt:  #EBF3FB;
  --green:    #2D7A3A;
  --green-lt: #E8F5EB;
  --amber:    #7A4A0A;
  --amber-lt: #FDF3E3;
  --red:      #A02020;
  --red-lt:   #FCECEC;
  --radius:   10px;
  --shadow:   0 1px 4px rgba(0,0,0,.07);
}

*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
html, body { height:100%; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
       background:var(--bg); display:flex; min-height:100vh; color:var(--text1); font-size:14px; }

/* ── SIDEBAR ──────────────────────────────────────────────────── */
.sidebar {
  width:230px; background:var(--surface); border-right:1px solid var(--border);
  display:flex; flex-direction:column; flex-shrink:0; position:sticky; top:0; height:100vh;
}
.logo { padding:18px 16px 14px; border-bottom:1px solid var(--border2); }
.logo-name { font-size:16px; font-weight:700; color:var(--blue); letter-spacing:-.3px; }
.logo-sub  { font-size:10px; color:var(--text3); margin-top:3px; text-transform:uppercase; letter-spacing:.6px; }

.nav-sec { padding:14px 16px 4px; font-size:9.5px; color:var(--text3);
           text-transform:uppercase; letter-spacing:.08em; font-weight:600; }
.nav {
  display:flex; align-items:center; gap:8px; padding:9px 16px;
  font-size:13px; color:var(--text2); text-decoration:none;
  border-left:2px solid transparent; transition:all .12s;
}
.nav:hover { background:var(--bg); color:var(--text1); }
.nav.active { color:var(--blue); border-left-color:var(--blue); background:var(--blue-lt); font-weight:600; }
.nav .count {
  margin-left:auto; background:var(--amber-lt); color:var(--amber);
  font-size:10px; font-weight:700; padding:2px 7px; border-radius:10px;
}
.nav .count.urgent { background:var(--red-lt); color:var(--red); }

.sidebar-foot { margin-top:auto; padding:14px 16px; border-top:1px solid var(--border2); }
.foot-name { font-size:12px; font-weight:600; color:var(--text1); }
.foot-role { font-size:10px; color:var(--text3); margin-top:2px; }
.logout { font-size:11px; color:var(--blue); text-decoration:none; margin-top:8px; display:inline-block; }
.logout:hover { text-decoration:underline; }

/* ── MAIN LAYOUT ─────────────────────────────────────────────── */
.main { flex:1; display:flex; flex-direction:column; min-width:0; overflow-y:auto; }
.topbar {
  background:var(--surface); border-bottom:1px solid var(--border);
  padding:16px 28px; position:sticky; top:0; z-index:10;
}
.tb-title { font-size:18px; font-weight:700; color:var(--text1); }
.tb-sub   { font-size:12px; color:var(--text2); margin-top:2px; }
.content  { flex:1; padding:24px 28px; }

/* ── GRID HELPERS ────────────────────────────────────────────── */
.g2  { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px; }
.g3  { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:20px; }
.g4  { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:20px; }
.g5  { display:grid; grid-template-columns:repeat(5,1fr); gap:14px; margin-bottom:20px; }

/* ── CARDS ───────────────────────────────────────────────────── */
.card {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--radius); padding:18px 20px;
  box-shadow:var(--shadow);
}
.card + .card { margin-top:16px; }
.card-h {
  font-size:13px; font-weight:700; color:var(--text1);
  margin-bottom:14px; display:flex; justify-content:space-between; align-items:center;
  text-transform:uppercase; letter-spacing:.04em;
}
.card-h a { color:var(--blue); text-decoration:none; font-weight:400; font-size:11px; text-transform:none; }
.card-h a:hover { text-decoration:underline; }

/* ── STAT CARDS ──────────────────────────────────────────────── */
.stat {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--radius); padding:18px 20px;
  box-shadow:var(--shadow); position:relative; overflow:hidden;
}
.stat::before {
  content:''; position:absolute; top:0; left:0; right:0; height:3px;
  background:var(--blue); border-radius:var(--radius) var(--radius) 0 0;
}
.stat.warn::before  { background:var(--amber); }
.stat.danger::before { background:var(--red); }
.stat.ok::before    { background:var(--green); }
.stat-label { font-size:10px; font-weight:700; color:var(--text3);
              text-transform:uppercase; letter-spacing:.06em; margin-bottom:10px; }
.stat-value { font-size:36px; font-weight:800; color:var(--text1); line-height:1; }
.stat.warn  .stat-value  { color:var(--amber); }
.stat.danger .stat-value { color:var(--red); }
.stat.ok    .stat-value  { color:var(--green); }
.stat-sub   { font-size:11px; color:var(--text3); margin-top:6px; }
.stat-link  { display:inline-block; font-size:11px; color:var(--blue); font-weight:600;
              margin-top:10px; text-decoration:none; }
.stat-link:hover { text-decoration:underline; }

/* ── TABLE ───────────────────────────────────────────────────── */
.tbl { width:100%; border-collapse:collapse; font-size:13px; }
.tbl th {
  text-align:left; padding:8px 10px; color:var(--text3);
  border-bottom:1px solid var(--border); font-weight:600; font-size:11px;
  text-transform:uppercase; letter-spacing:.04em;
}
.tbl td { padding:10px; border-bottom:1px solid var(--border2); vertical-align:top; }
.tbl tr:last-child td { border-bottom:none; }
.tbl tr:hover td { background:#FAFAF7; }

/* ── BADGES ──────────────────────────────────────────────────── */
.badge { display:inline-block; font-size:11px; font-weight:600;
         padding:3px 10px; border-radius:20px; white-space:nowrap; }
.bg { background:var(--green-lt); color:var(--green); }
.ba { background:var(--amber-lt); color:var(--amber); }
.bb { background:var(--blue-lt);  color:var(--blue);  }
.br { background:var(--red-lt);   color:var(--red);   }

/* ── BUTTONS ─────────────────────────────────────────────────── */
.btn {
  display:inline-block; padding:8px 16px; border-radius:8px;
  font-size:13px; font-weight:600; cursor:pointer;
  border:1px solid var(--border); background:var(--surface);
  color:var(--text1); transition:all .12s; font-family:inherit;
}
.btn:hover { background:var(--bg); }
.btn-primary { background:var(--blue); color:#fff; border-color:var(--blue); }
.btn-primary:hover { background:var(--blue-dk); border-color:var(--blue-dk); }
.btn-success { background:var(--green); color:#fff; border-color:var(--green); }
.btn-success:hover { background:#235e2d; }
.btn-danger  { background:var(--red);   color:#fff; border-color:var(--red); }
.btn-danger:hover { background:#7d1818; }
.btn-sm { padding:5px 12px; font-size:12px; }

/* ── FORM ELEMENTS ───────────────────────────────────────────── */
.form-lbl { display:block; font-size:11px; font-weight:700; color:var(--text2);
            text-transform:uppercase; letter-spacing:.04em; margin-bottom:5px; }
.form-inp, .form-sel, .form-ta {
  width:100%; padding:9px 12px; border:1px solid var(--border);
  border-radius:8px; font-size:13px; color:var(--text1);
  background:var(--surface); font-family:inherit; transition:border-color .12s;
}
.form-inp:focus, .form-sel:focus, .form-ta:focus { border-color:var(--blue); outline:none; }
.form-group { margin-bottom:14px; }
.form-ta { resize:vertical; min-height:80px; }

/* ── ALERTS ──────────────────────────────────────────────────── */
.alert { padding:12px 16px; border-radius:8px; font-size:13px; margin-bottom:16px; }
.alert-success { background:var(--green-lt); color:var(--green); border:1px solid #b6d9bd; }
.alert-danger  { background:var(--red-lt);   color:var(--red);   border:1px solid #f0b8b8; }
.alert-warn    { background:var(--amber-lt); color:var(--amber); border:1px solid #f0d09a; }
.alert-info    { background:var(--blue-lt);  color:var(--blue);  border:1px solid #b3d0ed; }

/* ── ACTIVITY FEED ───────────────────────────────────────────── */
.act-row { display:flex; gap:10px; padding:10px 0; border-bottom:1px solid var(--border2);
           align-items:flex-start; }
.act-row:last-child { border-bottom:none; }
.act-icon { width:30px; height:30px; border-radius:50%; background:var(--blue-lt);
            display:flex; align-items:center; justify-content:center; font-size:13px; flex-shrink:0; }
.act-body { flex:1; min-width:0; }
.act-desc { font-size:13px; color:var(--text1); line-height:1.4; }
.act-meta { font-size:11px; color:var(--text3); margin-top:3px; }
</style>
