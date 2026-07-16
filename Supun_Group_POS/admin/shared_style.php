:root {
    --primary:    #0f766e;
    --primary-dk: #115e59;
    --primary-lt: #ecfdf5;
    --primary-mid:#2dd4bf;
    --indigo:     #4f46e5;
    --indigo-lt:  #eef2ff;
    --green:      #16a34a;
    --green-lt:   #f0fdf4;
    --amber:      #d97706;
    --amber-lt:   #fffbeb;
    --sky:        #0284c7;
    --sky-lt:     #f0f9ff;
    --red:        #dc2626;
    --red-lt:     #fef2f2;
    --bg:         #f4f7f9;
    --white:      #ffffff;
    --border:     #e0e3ef;
    --border-dk:  #c8ccd8;
    --text:       #102a43;
    --text-mid:   #334e68;
    --text-muted: #829ab1;
    --sidebar-w:  240px;
    --topbar-h:   62px;
    --shadow-sm:  0 1px 3px rgba(0,0,0,.07);
    --shadow-md:  0 4px 16px rgba(0,0,0,.09);
    --shadow-lg:  0 10px 36px rgba(0,0,0,.13);
    --radius:     12px;
    --radius-sm:  8px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body { font-family: 'Nunito', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; }

/* ─── SIDEBAR ─── */
.sidebar { width: var(--sidebar-w); background: var(--white); border-right: 1.5px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; height: 100vh; z-index: 200; box-shadow: 2px 0 12px rgba(0,0,0,.05); }
.sb-brand { padding: 16px 18px 14px; border-bottom: 1.5px solid var(--border); display: flex; align-items: center; gap: 10px; }
.sb-logo { width: 36px; height: 36px; background: var(--primary); border-radius: 9px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 16px; box-shadow: 0 3px 8px rgba(217,92,43,.3); flex-shrink: 0; }
.sb-brand-text h2 { font-family: 'Lora', serif; font-size: 14px; color: var(--text); line-height: 1.1; }
.sb-brand-text small { font-size: 9px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .1em; font-weight: 700; }
.sb-nav { flex: 1; overflow-y: auto; padding: 10px 0; }
.nav-group-label { font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: .12em; color: var(--text-muted); padding: 9px 18px 4px; }
.nav-item { display: flex; align-items: center; gap: 9px; padding: 9px 18px; font-size: 13px; font-weight: 800; color: var(--text-mid); text-decoration: none; cursor: pointer; transition: all .15s; border: none; background: none; width: 100%; text-align: left; font-family: 'Nunito', sans-serif; position: relative; }
.nav-item i { width: 16px; text-align: center; font-size: 13px; color: var(--text-muted); transition: color .15s; }
.nav-item:hover { background: var(--bg); color: var(--primary); }
.nav-item:hover i { color: var(--primary); }
.nav-item.active { background: var(--primary-lt); color: var(--primary); }
.nav-item.active i { color: var(--primary); }
.nav-item.active::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--primary); border-radius: 0 3px 3px 0; }
.sb-footer { padding: 12px 14px; border-top: 1.5px solid var(--border); }
.sb-user { display: flex; align-items: center; gap: 9px; padding: 9px 11px; background: var(--bg); border-radius: var(--radius-sm); margin-bottom: 8px; }
.sb-avatar { width: 30px; height: 30px; background: linear-gradient(135deg,#6366f1,#8b5cf6); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 900; color: #fff; flex-shrink: 0; }
.sb-user-info .name { font-size: 12px; font-weight: 800; color: var(--text); }
.sb-user-info .role { font-size: 9px; font-weight: 900; color: var(--primary); text-transform: uppercase; letter-spacing: .06em; }
.btn-logout-sb { display: flex; align-items: center; justify-content: center; gap: 7px; width: 100%; padding: 8px; background: var(--red-lt); border: 1.5px solid #fca5a5; border-radius: var(--radius-sm); color: var(--red); font-size: 12px; font-weight: 800; font-family: 'Nunito', sans-serif; cursor: pointer; text-decoration: none; transition: all .15s; }
.btn-logout-sb:hover { background: var(--red); color: #fff; border-color: var(--red); }

/* ─── MAIN / TOPBAR ─── */
.main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }
.topbar { background: var(--white); border-bottom: 1.5px solid var(--border); height: var(--topbar-h); padding: 0 22px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; box-shadow: var(--shadow-sm); }
.topbar-left { display: flex; align-items: center; gap: 8px; }
.mobile-menu-btn { display:none;width:38px;height:38px;border:1.5px solid var(--border);border-radius:9px;background:var(--white);color:var(--text);align-items:center;justify-content:center;cursor:pointer;font-size:15px; }
.sidebar-overlay { display:none;position:fixed;inset:0;background:rgba(15,23,42,.42);backdrop-filter:blur(2px);z-index:190; }
.page-title-tb { font-family: 'Lora', serif; font-size: 16px; color: var(--text); }
.breadcrumb { font-size: 11px; color: var(--text-muted); font-weight: 700; display: flex; align-items: center; gap: 4px; }
.topbar-right { display: flex; align-items: center; gap: 8px; }
.date-badge { background: var(--bg); border: 1.5px solid var(--border); border-radius: var(--radius-sm); padding: 6px 12px; font-size: 12px; font-weight: 800; color: var(--text-mid); display: flex; align-items: center; gap: 5px; }

/* ─── CONTENT / PAGE HEADER ─── */
.content { padding: 20px 22px 32px; flex: 1; }
.page-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 18px; flex-wrap: wrap; gap: 10px; }
.page-title-h { font-family: 'Lora', serif; font-size: 20px; display: flex; align-items: center; gap: 9px; margin-bottom: 2px; }
.page-title-h i { color: var(--primary); }
.page-sub { font-size: 12px; color: var(--text-muted); font-weight: 700; }

/* ─── CARD ─── */
.card { background: var(--white); border: 1.5px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); }
/* Support both card-header and card-head */
.card-header, .card-head {
    padding: 13px 18px;
    border-bottom: 1.5px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 8px;
}
.card-header h3, .card-head h4 {
    font-size: 14px; font-weight: 900;
    display: flex; align-items: center; gap: 7px; margin: 0;
}
.card-header h3 i, .card-head h4 i { color: var(--primary); font-size: 13px; }
.card-body { padding: 18px; }
.table-card-full { overflow: hidden; }
.count-badge { background: var(--primary-lt); color: var(--primary); font-size: 11px; font-weight: 900; padding: 3px 10px; border-radius: 40px; }

/* ─── TABLE ─── */
.tbl-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th { padding: 10px 14px; font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted); background: var(--bg); text-align: left; border-bottom: 1.5px solid var(--border); white-space: nowrap; }
td { padding: 11px 14px; font-size: 13px; font-weight: 700; border-bottom: 1px solid var(--border); color: var(--text-mid); }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #fafbfd; }
.empty-row { text-align: center; color: var(--text-muted); padding: 28px !important; }

/* ─── BUTTONS ─── */
/* Primary */
.btn-primary,
.btn-tb.btn-primary {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 18px; background: var(--primary); color: #fff;
    border: none; border-radius: var(--radius-sm);
    font-size: 13px; font-weight: 800;
    font-family: 'Nunito', sans-serif;
    cursor: pointer; text-decoration: none;
    box-shadow: 0 2px 8px rgba(217,92,43,.28);
    transition: all .16s; white-space: nowrap;
}
.btn-primary:hover,
.btn-tb.btn-primary:hover { background: var(--primary-dk); transform: translateY(-1px); }

/* Outline / Secondary */
.btn-secondary,
.btn-outline,
.btn-tb.btn-outline {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 16px; background: var(--bg); color: var(--text-mid);
    border: 1.5px solid var(--border); border-radius: var(--radius-sm);
    font-size: 13px; font-weight: 800;
    font-family: 'Nunito', sans-serif;
    cursor: pointer; text-decoration: none; transition: all .16s; white-space: nowrap;
}
.btn-secondary:hover,
.btn-outline:hover,
.btn-tb.btn-outline:hover { border-color: var(--border-dk); color: var(--text); }

/* Edit / Delete row buttons */
.btn-edit { display: inline-flex; align-items: center; gap: 5px; padding: 5px 11px; background: #eff6ff; color: #3b82f6; border: 1px solid #bfdbfe; border-radius: 6px; font-size: 12px; font-weight: 800; font-family: 'Nunito', sans-serif; cursor: pointer; text-decoration: none; transition: all .14s; }
.btn-edit:hover { background: #3b82f6; color: #fff; border-color: #3b82f6; }
.btn-del, .btn-danger { display: inline-flex; align-items: center; gap: 5px; padding: 5px 11px; background: var(--red-lt); color: var(--red); border: 1px solid #fca5a5; border-radius: 6px; font-size: 12px; font-weight: 800; font-family: 'Nunito', sans-serif; cursor: pointer; text-decoration: none; transition: all .14s; }
.btn-del:hover, .btn-danger:hover { background: var(--red); color: #fff; border-color: var(--red); }
.action-btns { display: flex; gap: 5px; align-items: center; }

/* ─── FORMS ─── */
.field { margin-bottom: 13px; }
.field label { display: block; font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .09em; color: var(--text-mid); margin-bottom: 5px; }
.inp-wrap { position: relative; width: 100%; }
.inp-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 12px; pointer-events: none; z-index: 2; width: 14px; text-align: center; }
.inp-wrap .inp { padding-left: 38px; }
.inp-wrap:focus-within i { color: var(--primary); }
.inp {
    background: var(--bg); border: 1.5px solid var(--border);
    border-radius: var(--radius-sm); padding: 10px 12px;
    font-size: 13px; font-family: 'Nunito', sans-serif;
    font-weight: 700; color: var(--text); width: 100%; outline: none;
    transition: border-color .15s, box-shadow .15s;
}
.inp::placeholder { color: var(--text-muted); font-weight: 600; }
.inp:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(217,92,43,.1); }
select.inp { padding-left: 12px; }

/* Report and listing filter controls */
.filter-form,
.filter-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 10px;
}
.filter-bar {
    background: var(--white);
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    padding: 14px 16px;
}
.ff {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 150px;
    flex: 1 1 150px;
}
.ff label {
    display: block;
    font-size: 10px;
    line-height: 1.2;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--text-muted);
}
.ff input,
.ff select {
    width: 100%;
    height: 40px;
    padding: 0 11px;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--bg);
    color: var(--text);
    font: 700 13px 'Nunito', sans-serif;
    outline: none;
    transition: border-color .15s, box-shadow .15s, background .15s;
}
.ff input:hover,
.ff select:hover { border-color: var(--border-dk); background: var(--white); }
.ff input:focus,
.ff select:focus {
    border-color: var(--primary);
    background: var(--white);
    box-shadow: 0 0 0 3px rgba(15,118,110,.1);
}
.filter-actions { display:flex;align-items:center;gap:8px;flex-wrap:wrap; }

.two-col { display: grid; grid-template-columns: 340px 1fr; gap: 16px; align-items: start; }
.two-field { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

/* ─── ALERTS ─── */
.alert { padding: 11px 14px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 800; display: flex; align-items: center; gap: 8px; margin-bottom: 16px; border: 1.5px solid; }
.alert-success { background: var(--green-lt); color: var(--green); border-color: #86efac; }
.alert-error   { background: var(--red-lt);   color: var(--red);   border-color: #fca5a5; }
.alert-warning { background: var(--amber-lt); color: var(--amber); border-color: #fde68a; }

/* ─── STATUS / BADGES ─── */
.status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 40px; font-size: 11px; font-weight: 900; cursor: pointer; text-decoration: none; transition: all .14s; border: 1px solid; }
.st-active   { background: var(--green-lt); color: var(--green); border-color: #86efac; }
.st-inactive { background: var(--bg); color: var(--text-muted); border-color: var(--border-dk); }

.badge, .badge-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; border-radius: 40px;
    font-size: 11px; font-weight: 900; white-space: nowrap;
    border: 1px solid transparent;
}
.b-orange { background: var(--primary-lt); color: var(--primary);  border-color: #f9c4a6; }
.b-indigo { background: var(--indigo-lt);  color: var(--indigo);   border-color: #c7d2fe; }
.b-green  { background: var(--green-lt);   color: var(--green);    border-color: #86efac; }
.b-amber  { background: var(--amber-lt);   color: var(--amber);    border-color: #fde68a; }
.b-sky    { background: var(--sky-lt);     color: var(--sky);      border-color: #bae6fd; }
.b-red    { background: var(--red-lt);     color: var(--red);      border-color: #fca5a5; }

.bp-cash { background: var(--green-lt);   color: var(--green);  }
.bp-card { background: var(--indigo-lt);  color: var(--indigo); }
.bp-qr   { background: var(--amber-lt);   color: var(--amber);  }
.bp-bank { background: var(--sky-lt);     color: var(--sky);    }
.bp-dine { background: var(--primary-lt); color: var(--primary);}
.bp-take { background: var(--amber-lt);   color: var(--amber);  }

/* ─── PAGINATION ─── */
.pagination { display: flex; align-items: center; gap: 6px; padding: 14px 18px; border-top: 1.5px solid var(--border); flex-wrap: wrap; }
.pag-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 34px; padding: 0 10px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 800; font-family: 'Nunito', sans-serif; border: 1.5px solid var(--border); background: var(--white); color: var(--text-mid); text-decoration: none; transition: all .14s; cursor: pointer; }
.pag-btn:hover { border-color: var(--primary); color: var(--primary); }
.pag-btn.active { background: var(--primary); border-color: var(--primary); color: #fff; }
.pag-btn.disabled { opacity: .4; pointer-events: none; }

/* ─── STAT STRIP / KPI ─── */
.stat-strip { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; margin-bottom: 18px; }
.stat-tile { background: var(--white); border: 1.5px solid var(--border); border-radius: var(--radius); padding: 14px 16px; display: flex; align-items: center; gap: 12px; box-shadow: var(--shadow-sm); }
.st-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
.st-val { font-size: 20px; font-weight: 900; font-family: 'Lora', serif; color: var(--text); }
.st-lbl { font-size: 11px; font-weight: 700; color: var(--text-muted); }

/* ─── SCROLLBAR ─── */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-thumb { background: var(--border-dk); border-radius: 5px; }

/* ─── RESPONSIVE ─── */
@media (max-width: 1100px) { .stat-strip { grid-template-columns: 1fr 1fr; } }
@media (max-width: 900px) {
    :root { --sidebar-w: 0px; }
    .sidebar { width:240px;transform: translateX(-100%);transition:transform .22s ease; }
    .sidebar.open { transform:translateX(0); }
    .sidebar-overlay.show { display:block; }
    body.menu-open { overflow:hidden; }
    .mobile-menu-btn { display:inline-flex; }
    .main { margin-left: 0; }
    .topbar { padding:0 14px; }
    .topbar-right .date-badge { display:none; }
    .topbar-right .btn-primary { padding:8px 11px;font-size:0; }
    .topbar-right .btn-primary i { font-size:13px; }
    .content { padding:16px 14px 26px; }
    .two-col { grid-template-columns: 1fr; }
    .stat-strip { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 560px) {
    .stat-strip { grid-template-columns:1fr; }
    .two-field { grid-template-columns:1fr; }
    .page-header { align-items:stretch; }
    .page-header > div:last-child { width:100%;flex-wrap:wrap; }
    th,td { padding:9px 10px; }
    .ff { flex-basis:100%;min-width:100%; }
    .filter-form > .btn-primary,
    .filter-form > .btn-secondary,
    .filter-bar > .btn-primary,
    .filter-bar > .btn-secondary { flex:1;justify-content:center; }
}
@media print {
    .sidebar, .topbar, .filter-bar, .no-print, .pagination { display: none !important; }
    .main { margin-left: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ddd !important; }
}
