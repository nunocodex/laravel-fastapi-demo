<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AI Enterprise — RAG Demo</title>
    <style>
        :root {
            --bg: #0b0f19;
            --surface: #151b2b;
            --surface-2: #1e2639;
            --border: #2a344d;
            --text: #f0f4f8;
            --muted: #94a3b8;
            --accent: #38bdf8;
            --accent-2: #818cf8;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --radius: 12px;
            --font: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: var(--font);
            height: 100vh;
            overflow: hidden;
        }
        .app {
            display: flex;
            height: 100vh;
        }
        header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 56px;
            background: rgba(11, 15, 25, 0.95);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1rem;
            z-index: 20;
            backdrop-filter: blur(8px);
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 700;
            font-size: 1.05rem;
        }
        .brand span { color: var(--accent); }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .status {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.25rem 0.7rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(245, 158, 11, 0.12);
            color: var(--warning);
        }
        .status.up { background: rgba(34, 197, 94, 0.12); color: var(--success); }
        .status.down { background: rgba(239, 68, 68, 0.12); color: var(--danger); }
        .btn-icon {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 8px;
            width: 36px;
            height: 36px;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .sidebar {
            width: 320px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            padding-top: 56px;
            transition: transform 0.25s ease;
        }
        .sidebar-section {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
        }
        .sidebar-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
            margin-bottom: 0.75rem;
            font-weight: 600;
        }
        .upload-area {
            border: 2px dashed var(--border);
            border-radius: var(--radius);
            padding: 1.5rem 1rem;
            text-align: center;
            color: var(--muted);
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            min-height: 120px;
        }
        .upload-area svg {
            width: 32px;
            height: 32px;
            color: var(--accent);
            opacity: 0.85;
            transition: transform 0.2s ease;
        }
        .upload-area:hover, .upload-area.dragover {
            border-color: var(--accent);
            background: rgba(56, 189, 248, 0.07);
            color: var(--text);
        }
        .upload-area:hover svg, .upload-area.dragover svg {
            transform: translateY(-2px);
            opacity: 1;
        }
        .upload-area input { display: none; }
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding-top: 56px;
            min-width: 0;
        }
        .chat {
            flex: 1;
            overflow-y: auto;
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .message {
            display: flex;
            gap: 0.75rem;
            max-width: 85%;
            animation: fadeIn 0.25s ease;
        }
        .message.user { align-self: flex-end; flex-direction: row-reverse; }
        .message.assistant { align-self: flex-start; }
        .avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .message.user .avatar { background: var(--accent); color: #020617; }
        .message.assistant .avatar { background: var(--accent-2); color: #020617; }
        .bubble {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 0.9rem 1rem;
            line-height: 1.55;
            white-space: normal;
        }
        .bubble p { margin: 0 0 0.6rem; }
        .bubble p:last-child { margin-bottom: 0; }
        .bubble h1, .bubble h2, .bubble h3 { margin: 0.75rem 0 0.4rem; color: var(--text); }
        .bubble h1 { font-size: 1.15rem; }
        .bubble h2 { font-size: 1.05rem; }
        .bubble h3 { font-size: 0.95rem; }
        .bubble strong { color: var(--accent); }
        .bubble ul { margin: 0.5rem 0; padding-left: 1.25rem; }
        .bubble li { margin: 0.25rem 0; }
        .bubble code {
            background: rgba(148, 163, 184, 0.15);
            padding: 0.15rem 0.35rem;
            border-radius: 4px;
            font-family: var(--mono);
            font-size: 0.85em;
        }
        .bubble pre {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.75rem;
            overflow-x: auto;
            margin: 0.5rem 0;
        }
        .bubble pre code { background: transparent; padding: 0; }
        .bubble a { color: var(--accent); text-decoration: underline; }
        .message.user .bubble {
            background: rgba(56, 189, 248, 0.12);
            border-color: rgba(56, 189, 248, 0.25);
        }
        .composer {
            border-top: 1px solid var(--border);
            padding: 1rem 1.25rem;
            background: var(--surface);
            display: flex;
            gap: 0.75rem;
            align-items: flex-end;
        }
        .composer textarea {
            flex: 1;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            color: var(--text);
            font-family: var(--font);
            font-size: 0.95rem;
            resize: none;
            min-height: 48px;
            max-height: 160px;
        }
        .composer textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.12);
        }
        .composer button {
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            border: none;
            color: #020617;
            font-weight: 700;
            padding: 0.7rem 1.25rem;
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.95rem;
        }
        .composer button:disabled { opacity: 0.5; cursor: not-allowed; }
        .empty-state {
            margin: auto;
            text-align: center;
            color: var(--muted);
        }
        .document-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .doc-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.65rem 0.75rem;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
        }
        .doc-item:hover { border-color: var(--accent); }
        .doc-item.active { border-color: var(--accent); background: rgba(56, 189, 248, 0.08); }
        .doc-name { display: flex; flex-direction: column; overflow: hidden; }
        .doc-name strong { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .doc-meta { font-size: 0.75rem; color: var(--muted); }
        .doc-actions { display: flex; gap: 0.35rem; }
        .doc-actions button {
            background: transparent;
            border: none;
            color: var(--muted);
            cursor: pointer;
            padding: 0.2rem;
            border-radius: 4px;
        }
        .doc-actions button:hover { color: var(--text); background: var(--surface-2); }
        .doc-actions button.danger:hover { color: var(--danger); }
        .sources {
            position: fixed;
            right: 0;
            top: 56px;
            bottom: 0;
            width: 340px;
            background: var(--surface);
            border-left: 1px solid var(--border);
            transform: translateX(100%);
            transition: transform 0.25s ease;
            z-index: 15;
            padding: 1rem;
            overflow-y: auto;
        }
        .sources.open { transform: translateX(0); }
        .source-card {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
            font-size: 0.85rem;
        }
        .source-card .score { color: var(--accent); font-weight: 700; font-size: 0.75rem; }
        .source-card .text { color: var(--muted); margin-top: 0.35rem; }
        .system-grid { display: grid; gap: 0.5rem; }
        .system-card {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.65rem 0.75rem;
            font-size: 0.8rem;
        }
        .system-card .label {
            color: var(--muted);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.2rem;
        }
        .system-card .value { font-weight: 600; }
        .system-card .value.up { color: var(--success); }
        .system-card .value.down { color: var(--danger); }
        .trace-panel {
            background: var(--surface);
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 1.25rem;
            font-size: 0.8rem;
            max-height: 180px;
            overflow-y: auto;
        }
        .trace-panel.hidden { display: none; }
        .trace-title {
            color: var(--muted);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.4rem;
        }
        .trace-step {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0;
            color: var(--muted);
        }
        .trace-step.done { color: var(--success); }
        .trace-step.active { color: var(--text); }
        .trace-step::before { content: '○'; }
        .trace-step.done::before { content: '●'; color: var(--success); }
        .trace-step.active::before { content: '◐'; color: var(--accent); }
        .tabs {}
        .tab {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--muted);
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .tab.active {
            background: var(--accent);
            border-color: var(--accent);
            color: #020617;
        }
        .tab-content.hidden { display: none; }
        .detail-card {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
        }
        .detail-card .title {
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 0.4rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .detail-card .title .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
        }
        .detail-card .title .dot.down { background: var(--danger); }
        .detail-card .row {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            padding: 0.2rem 0;
            color: var(--muted);
        }
        .detail-card .row span:last-child {
            color: var(--text);
            font-weight: 500;
            text-align: right;
            max-width: 60%;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .toast {
            position: fixed;
            bottom: 1.25rem;
            left: 50%;
            transform: translateX(-50%);
            background: var(--surface-2);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 0.75rem 1.25rem;
            border-radius: 10px;
            z-index: 30;
            display: none;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .toast.show { display: flex; }
        .toast.error { border-color: var(--danger); }
        .toast.success { border-color: var(--success); }
        .loading {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            color: var(--muted);
            font-size: 0.875rem;
        }
        .spinner { animation: spin 1s linear infinite; }
        @@keyframes spin { to { transform: rotate(360deg); } }
        @@keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

        @@media (max-width: 900px) {
            .btn-icon { display: inline-flex; }
            .sidebar {
                position: fixed;
                left: 0;
                top: 0;
                bottom: 0;
                z-index: 25;
                transform: translateX(-100%);
            }
            .sidebar.open { transform: translateX(0); }
            .overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.5);
                z-index: 24;
            }
            .overlay.show { display: block; }
            .sources { width: 100%; }
        }
    </style>
</head>
<body>
    <header>
        <div class="brand">
            <button class="btn-icon" id="menu-toggle">☰</button>
            <span>AI Enterprise</span> RAG Demo
        </div>
        <div class="header-actions">
            <span class="status" id="health-status">checking…</span>
            <button class="btn-icon" id="sources-toggle" style="display:inline-flex" title="Sorgenti">ℹ</button>
        </div>
    </header>

    <div class="overlay" id="overlay"></div>

    <div class="app">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-section">
                <div class="sidebar-title">Carica documento</div>
                <label class="upload-area" id="upload-area" for="file-input">
                    <input type="file" id="file-input" accept=".pdf,.txt,.md">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    <div id="upload-label">Trascina qui il file</div>
                    <div class="doc-meta" id="upload-meta">PDF, TXT o MD · max 10 MB</div>
                </label>
            </div>
            <div class="sidebar-section" style="flex:1; overflow:auto;">
                <div class="sidebar-title">Documenti indicizzati</div>
                <div class="document-list" id="document-list">
                    <div class="doc-meta">Nessun documento caricato.</div>
                </div>
            </div>
            <div class="sidebar-section">
                <div class="sidebar-title">Filtro attivo</div>
                <div class="doc-meta" id="active-filter">Tutti i documenti</div>
                <button id="clear-filter" style="margin-top:0.5rem; width:100%; padding:0.45rem; background:var(--surface-2); border:1px solid var(--border); color:var(--text); border-radius:6px; cursor:pointer; display:none;">Rimuovi filtro</button>
            </div>
        </aside>

        <main class="main">
            <div class="chat" id="chat">
                <div class="empty-state">
                    <h2 style="font-weight:500; margin-bottom:0.5rem;">Benvenuto nella demo RAG</h2>
                    <p>Carica un documento e inizia a fare domande in linguaggio naturale.</p>
                </div>
            </div>
            <div class="trace-panel hidden" id="trace-panel">
                <div class="trace-title" id="trace-title">Trace</div>
                <div id="trace-steps"></div>
            </div>
            <div class="composer">
                <textarea id="message-input" rows="1" placeholder="Scrivi una domanda…"></textarea>
                <button id="send-btn">Invia</button>
            </div>
        </main>
    </div>

    <aside class="sources" id="details-panel">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
            <div class="tabs" style="display:flex; gap:0.5rem;">
                <button class="tab active" data-tab="sources" id="tab-btn-sources">Sorgenti</button>
                <button class="tab" data-tab="system" id="tab-btn-system">Sistema</button>
            </div>
            <button id="close-details" class="btn-icon" style="display:inline-flex; width:32px; height:32px;">✕</button>
        </div>
        <div id="tab-sources" class="tab-content">
            <div id="sources-list">
                <div class="doc-meta">Nessuna sorgente da mostrare.</div>
            </div>
        </div>
        <div id="tab-system" class="tab-content hidden">
            <div id="system-list">
                <div class="doc-meta">Caricamento dettagli sistema…</div>
            </div>
        </div>
    </aside>

    <div class="toast" id="toast"></div>

    <script>
        const chat = document.getElementById('chat');
        const messageInput = document.getElementById('message-input');
        const sendBtn = document.getElementById('send-btn');
        const fileInput = document.getElementById('file-input');
        const uploadArea = document.getElementById('upload-area');
        const uploadLabel = document.getElementById('upload-label');
        const documentList = document.getElementById('document-list');
        const healthStatus = document.getElementById('health-status');
        const detailsPanel = document.getElementById('details-panel');
        const sourcesList = document.getElementById('sources-list');
        const systemList = document.getElementById('system-list');
        const detailsToggle = document.getElementById('sources-toggle');
        const closeDetails = document.getElementById('close-details');
        const tabBtnSources = document.getElementById('tab-btn-sources');
        const tabBtnSystem = document.getElementById('tab-btn-system');
        const tabSources = document.getElementById('tab-sources');
        const tabSystem = document.getElementById('tab-system');
        const activeFilter = document.getElementById('active-filter');
        const clearFilterBtn = document.getElementById('clear-filter');
        const toast = document.getElementById('toast');
        const sidebar = document.getElementById('sidebar');
        const menuToggle = document.getElementById('menu-toggle');
        const overlay = document.getElementById('overlay');
        const tracePanel = document.getElementById('trace-panel');
        const traceTitle = document.getElementById('trace-title');
        const traceSteps = document.getElementById('trace-steps');

        let sessionId = localStorage.getItem('rag_session_id') || crypto.randomUUID();
        localStorage.setItem('rag_session_id', sessionId);
        let selectedDocumentId = null;
        let isUploading = false;
        let currentDocs = [];

        function csrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        }

        function showToast(message, type = 'error') {
            toast.textContent = message;
            toast.className = 'toast show ' + type;
            setTimeout(() => toast.classList.remove('show'), 4000);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function autoResize(ta) {
            ta.style.height = 'auto';
            ta.style.height = Math.min(ta.scrollHeight, 160) + 'px';
        }

        messageInput.addEventListener('input', () => autoResize(messageInput));

        function renderMarkdown(text) {
            const lines = text.split('\n');
            const out = [];
            let inList = false;
            let inCode = false;
            let codeLines = [];

            function inline(s) {
                s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
                s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
                s = s.replace(/\*([^*]+)\*/g, '<em>$1</em>');
                s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
                return s;
            }

            function closeList() {
                if (inList) {
                    out.push('</ul>');
                    inList = false;
                }
            }

            for (const raw of lines) {
                if (raw.startsWith('```')) {
                    if (inCode) {
                        out.push('<pre><code>' + escapeHtml(codeLines.join('\n')) + '</code></pre>');
                        codeLines = [];
                        inCode = false;
                    } else {
                        closeList();
                        inCode = true;
                    }
                    continue;
                }
                if (inCode) {
                    codeLines.push(raw);
                    continue;
                }

                const line = escapeHtml(raw);
                if (line.startsWith('### ')) { closeList(); out.push('<h3>' + inline(line.slice(4)) + '</h3>'); continue; }
                if (line.startsWith('## ')) { closeList(); out.push('<h2>' + inline(line.slice(3)) + '</h2>'); continue; }
                if (line.startsWith('# ')) { closeList(); out.push('<h1>' + inline(line.slice(2)) + '</h1>'); continue; }

                const listMatch = line.match(/^([-*])\s+(.*)$/);
                if (listMatch) {
                    if (!inList) { out.push('<ul>'); inList = true; }
                    out.push('<li>' + inline(listMatch[2]) + '</li>');
                    continue;
                }

                closeList();
                if (line.trim() === '') {
                    out.push('<br>');
                    continue;
                }
                out.push('<p>' + inline(line) + '</p>');
            }
            closeList();
            if (inCode) {
                out.push('<pre><code>' + escapeHtml(codeLines.join('\n')) + '</code></pre>');
            }
            return out.join('');
        }

        function addMessage(role, text) {
            const empty = chat.querySelector('.empty-state');
            if (empty) empty.remove();

            const msg = document.createElement('div');
            msg.className = 'message ' + role;
            msg.innerHTML = `
                <div class="avatar">${role === 'user' ? 'TU' : 'AI'}</div>
                <div class="bubble">${role === 'assistant' ? renderMarkdown(text) : escapeHtml(text)}</div>
            `;
            chat.appendChild(msg);
            chat.scrollTop = chat.scrollHeight;
        }

        function addLoading() {
            const empty = chat.querySelector('.empty-state');
            if (empty) empty.remove();
            const msg = document.createElement('div');
            msg.className = 'message assistant';
            msg.id = 'loading-msg';
            msg.innerHTML = `
                <div class="avatar">AI</div>
                <div class="bubble"><span class="loading"><span class="spinner">◠</span> Sto pensando…</span></div>
            `;
            chat.appendChild(msg);
            chat.scrollTop = chat.scrollHeight;
        }

        function removeLoading() {
            const el = document.getElementById('loading-msg');
            if (el) el.remove();
        }

        async function checkHealth() {
            try {
                const res = await fetch('/demo/health', { headers: { Accept: 'application/json' } });
                const data = await res.json();
                const ok = data.status === 'ok';
                healthStatus.className = 'status ' + (ok ? 'up' : 'down');
                healthStatus.textContent = ok ? 'Stack online' : 'Stack degradato';
            } catch (e) {
                healthStatus.className = 'status down';
                healthStatus.textContent = 'Stack offline';
            }
        }



        function detailCard(title, rows, isUp = true) {
            const html = rows.map(r =>
                `<div class="row"><span>${escapeHtml(r.label)}</span><span title="${escapeHtml(String(r.value))}">${escapeHtml(String(r.value))}</span></div>`
            ).join('');
            return `
                <div class="detail-card">
                    <div class="title">
                        <span class="dot ${isUp ? '' : 'down'}"></span>
                        ${escapeHtml(title)}
                    </div>
                    ${html}
                </div>
            `;
        }

        async function loadStats() {
            try {
                const res = await fetch('/demo/stats', { headers: { Accept: 'application/json' } });
                const data = await res.json();
                const dbOk = data.db.status === 'up';
                const redisOk = data.redis.status === 'up';
                const qdrantOk = data.qdrant.status === 'up';
                const fastapiOk = data.fastapi.status === 'up';

                const dbRows = [
                    { label: 'Stato', value: data.db.status },
                    { label: 'Host', value: data.db.host },
                    { label: 'Database', value: data.db.database },
                    { label: 'Task totali', value: data.db.tasks_total },
                ];
                if (data.db.tasks_by_status && Object.keys(data.db.tasks_by_status).length) {
                    dbRows.push({
                        label: 'Per stato',
                        value: Object.entries(data.db.tasks_by_status)
                            .map(([k, v]) => `${k}: ${v}`)
                            .join(', '),
                    });
                }

                const redisRows = [
                    { label: 'Stato', value: data.redis.status },
                    { label: 'Host', value: `${data.redis.host}:${data.redis.port}` },
                ];

                const qdrantRows = [
                    { label: 'Stato', value: data.qdrant.status },
                    { label: 'Host', value: `${data.qdrant.host}:${data.qdrant.port}` },
                    { label: 'Collection', value: data.qdrant.collection_name },
                    { label: 'Vettori', value: `${data.qdrant.vector_size}d · ${data.qdrant.distance}` },
                    { label: 'Punti', value: data.qdrant.points_count },
                    { label: 'Documenti', value: data.qdrant.documents_count },
                ];

                const fastapiRows = [
                    { label: 'Stato', value: data.fastapi.status },
                    { label: 'Versione', value: data.fastapi.version },
                    { label: 'Embedding', value: data.fastapi.embedding_model },
                    { label: 'Dimensione vettore', value: data.fastapi.embedding_dim },
                    { label: 'Chunk size / overlap', value: `${data.fastapi.chunk_size} / ${data.fastapi.chunk_overlap}` },
                    { label: 'LLM provider', value: data.fastapi.llm_provider },
                    { label: 'LLM model', value: data.fastapi.llm_model || '—' },
                    { label: 'LLM status', value: data.fastapi.llm_status },
                ];

                systemList.innerHTML =
                    detailCard('Database Postgres', dbRows, dbOk) +
                    detailCard('Redis', redisRows, redisOk) +
                    detailCard('Qdrant Vector DB', qdrantRows, qdrantOk) +
                    detailCard('FastAPI Engine', fastapiRows, fastapiOk);
            } catch (e) {
                systemList.innerHTML = `<div class="doc-meta">Errore caricamento: ${escapeHtml(e.message)}</div>`;
            }
        }

        function renderTrace(title, steps) {
            traceTitle.textContent = title;
            traceSteps.innerHTML = '';
            steps.forEach(step => {
                const div = document.createElement('div');
                div.className = 'trace-step ' + (step.status || 'active');
                div.textContent = step.label;
                traceSteps.appendChild(div);
            });
            tracePanel.classList.remove('hidden');
        }

        function createDocItem(doc) {
            const item = document.createElement('div');
            item.className = 'doc-item' + (doc.document_id === selectedDocumentId ? ' active' : '');
            item.innerHTML = `
                <div class="doc-name" title="${escapeHtml(doc.filename)} (${doc.num_chunks} chunk)">
                    <strong>${escapeHtml(doc.filename)}</strong>
                    <span class="doc-meta">${doc.num_chunks} chunk · ${doc.document_id.substring(0,8)}</span>
                </div>
                <div class="doc-actions">
                    <button title="Usa come filtro">🔍</button>
                    <button class="danger" title="Elimina">🗑</button>
                </div>
            `;
            item.querySelector('button[title="Usa come filtro"]').addEventListener('click', (e) => {
                e.stopPropagation();
                selectedDocumentId = doc.document_id;
                updateFilter();
                renderDocList();
            });
            item.querySelector('button.danger').addEventListener('click', async (e) => {
                e.stopPropagation();
                if (!confirm('Eliminare questo documento?')) return;
                try {
                            const del = await fetch(`/demo/documents/${doc.document_id}`, {
                                method: 'DELETE',
                                headers: { 'X-CSRF-TOKEN': csrfToken() },
                            });
                    if (del.ok) {
                        showToast('Documento eliminato', 'success');
                        if (selectedDocumentId === doc.document_id) {
                            selectedDocumentId = null;
                            updateFilter();
                        }
                        loadDocuments();
                    } else {
                        showToast('Errore durante l\'eliminazione');
                    }
                } catch (err) {
                    showToast(err.message);
                }
            });
            return item;
        }

        async function loadDocuments() {
            try {
                const res = await fetch('/demo/documents', { headers: { Accept: 'application/json' } });
                const data = await res.json();
                currentDocs = data.documents || [];
                if (currentDocs.length === 0) {
                    documentList.innerHTML = '<div class="doc-meta">Nessun documento caricato.</div>';
                    return;
                }
                renderDocList();
            } catch (e) {
                showToast('Impossibile caricare i documenti');
            }
        }

        function renderDocList() {
            documentList.innerHTML = '';
            currentDocs.forEach(doc => documentList.appendChild(createDocItem(doc)));
        }

        function updateFilter() {
            if (selectedDocumentId) {
                activeFilter.textContent = 'Documento: ' + selectedDocumentId.substring(0, 8) + '…';
                clearFilterBtn.style.display = 'block';
            } else {
                activeFilter.textContent = 'Tutti i documenti';
                clearFilterBtn.style.display = 'none';
            }
        }

        clearFilterBtn.addEventListener('click', () => {
            selectedDocumentId = null;
            updateFilter();
            loadDocuments();
        });

        uploadArea.addEventListener('dragover', (e) => { e.preventDefault(); uploadArea.classList.add('dragover'); });
        uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('dragover'));
        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            if (e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]);
        });
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length) handleFile(fileInput.files[0]);
        });

        async function handleFile(file) {
            if (isUploading) return;
            if (file.size > 10 * 1024 * 1024) {
                showToast('File troppo grande (max 10 MB)');
                return;
            }
            isUploading = true;
            uploadLabel.textContent = 'Caricamento in corso…';
            renderTrace(`Upload: ${file.name}`, [
                { label: `Ricevuto ${(file.size / 1024).toFixed(1)} KB`, status: 'done' },
                { label: 'Estrazione testo…', status: 'active' },
                { label: 'Chunking & embedding…', status: '' },
                { label: 'Indicizzazione Qdrant…', status: '' },
            ]);
            const formData = new FormData();
            formData.append('file', file);
            try {
                const res = await fetch('/demo/documents', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken() },
                    body: formData,
                });
                const data = await res.json();
                if (res.ok && data.document_id) {
                    renderTrace(`Upload: ${file.name}`, [
                        { label: `Ricevuto ${(file.size / 1024).toFixed(1)} KB`, status: 'done' },
                        { label: 'Testo estratto', status: 'done' },
                        { label: `${data.num_chunks} chunk · modello ${data.embedding_model} (${data.embedding_dim}d)`, status: 'done' },
                        { label: `Upsert in Qdrant (${data.collection_name})`, status: 'done' },
                    ]);
                    showToast(`Caricato: ${data.num_chunks} chunk`, 'success');
                    loadDocuments();
                    loadStats();
                } else {
                    renderTrace(`Upload: ${file.name}`, [
                        { label: data.error || 'Errore caricamento', status: 'down' },
                    ]);
                    showToast(data.error || 'Errore caricamento');
                }
            } catch (e) {
                renderTrace(`Upload: ${file.name}`, [
                    { label: `Errore: ${e.message}`, status: 'down' },
                ]);
                showToast(e.message);
            } finally {
                isUploading = false;
                uploadLabel.textContent = 'Trascina qui il file';
                fileInput.value = '';
            }
        }

        async function sendMessage() {
            const text = messageInput.value.trim();
            if (!text) return;

            addMessage('user', text);
            messageInput.value = '';
            messageInput.style.height = 'auto';
            sendBtn.disabled = true;
            addLoading();
            renderTrace('RAG pipeline', [
                { label: 'Embedding della domanda…', status: 'active' },
                { label: 'Ricerca vettoriale su Qdrant…', status: '' },
                { label: 'Costruzione prompt con contesto…', status: '' },
                { label: 'Chiamata LLM…', status: '' },
            ]);

            try {
                const res = await fetch('/demo/chat', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({
                        message: text,
                        session_id: sessionId,
                        document_id: selectedDocumentId,
                    }),
                });
                const data = await res.json();
                removeLoading();
                if (res.ok && data.reply) {
                    addMessage('assistant', data.reply);
                    if (data.session_id) {
                        sessionId = data.session_id;
                        localStorage.setItem('rag_session_id', sessionId);
                    }
                    renderSources(data.sources || []);
                    renderTrace('RAG pipeline completata', [
                        { label: `Embedding della domanda`, status: 'done' },
                        { label: `Retrieved ${data.retrieved_chunks} chunk da Qdrant`, status: 'done' },
                        { label: `Prompt costruito con ${data.prompt_tokens || '?'} token`, status: 'done' },
                        { label: `Risposta da ${data.model} (${data.completion_tokens || '?'} token)`, status: 'done' },
                    ]);
                } else {
                    renderTrace('RAG pipeline', [
                        { label: data.error || 'Errore risposta', status: 'down' },
                    ]);
                    addMessage('assistant', 'Errore: ' + (data.error || 'risposta non valida'));
                }
            } catch (e) {
                removeLoading();
                renderTrace('RAG pipeline', [
                    { label: `Errore di rete: ${e.message}`, status: 'down' },
                ]);
                addMessage('assistant', 'Errore di rete: ' + e.message);
            } finally {
                sendBtn.disabled = false;
                messageInput.focus();
                loadStats();
            }
        }

        function renderSources(sources) {
            if (!sources.length) {
                sourcesList.innerHTML = '<div class="doc-meta">Nessuna sorgente da mostrare.</div>';
                return;
            }
            sourcesList.innerHTML = '';
            sources.forEach((src, i) => {
                const card = document.createElement('div');
                card.className = 'source-card';
                card.innerHTML = `
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.35rem;">
                        <span class="score">#${i + 1} score ${(src.score || 0).toFixed(3)}</span>
                        <span class="doc-meta">${escapeHtml(src.filename || '')}</span>
                    </div>
                    <div class="text">${escapeHtml(src.text || '')}</div>
                `;
                sourcesList.appendChild(card);
            });
        }

        sendBtn.addEventListener('click', sendMessage);
        messageInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        detailsToggle.addEventListener('click', () => detailsPanel.classList.add('open'));
        closeDetails.addEventListener('click', () => detailsPanel.classList.remove('open'));

        tabBtnSources.addEventListener('click', () => {
            tabBtnSources.classList.add('active');
            tabBtnSystem.classList.remove('active');
            tabSources.classList.remove('hidden');
            tabSystem.classList.add('hidden');
        });
        tabBtnSystem.addEventListener('click', () => {
            tabBtnSystem.classList.add('active');
            tabBtnSources.classList.remove('active');
            tabSystem.classList.remove('hidden');
            tabSources.classList.add('hidden');
        });

        function toggleSidebar() { sidebar.classList.toggle('open'); overlay.classList.toggle('show'); }
        menuToggle.addEventListener('click', toggleSidebar);
        overlay.addEventListener('click', toggleSidebar);

        checkHealth();
        loadDocuments();
        loadStats();
        setInterval(checkHealth, 15000);
        setInterval(loadStats, 10000);
    </script>
</body>
</html>
