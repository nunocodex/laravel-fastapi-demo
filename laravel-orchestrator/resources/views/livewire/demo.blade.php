<div class="demo-app">
    <header class="fixed top-0 left-0 right-0 h-14 bg-[#0b0f19]/95 border-b border-[#2a344d] flex items-center justify-between px-4 z-20 backdrop-blur-lg">
        <div class="flex items-center gap-3 font-bold text-base">
            <button class="btn-icon lg:hidden inline-flex" wire:click="togglePanel">☰</button>
            <span class="text-[#38bdf8]">AI Enterprise</span> RAG Demo
        </div>
        <div class="flex items-center gap-3">
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold status-dot {{ $healthStatus === 'Stack online' ? 'health-up' : 'health-down' }}">
                {{ $healthStatus }}
            </span>
            <button class="btn-icon" wire:click="togglePanel" title="Pannello">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
            </button>
        </div>
    </header>

    <div class="flex h-screen pt-14">
        {{-- Sidebar --}}
        <aside class="w-80 bg-[#151b2b] border-r border-[#2a344d] flex flex-col shrink-0 max-lg:fixed max-lg:inset-y-0 max-lg:left-0 max-lg:z-25 max-lg:-translate-x-full transition-transform {{ $panelOpen ? 'max-lg:translate-x-0' : '' }}">
            <div class="p-4 border-b border-[#2a344d]">
                <div class="text-xs uppercase tracking-widest text-[#94a3b8] mb-3 font-semibold">Carica documento</div>
                <label class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-[#2a344d] rounded-xl p-6 text-center text-[#94a3b8] text-sm cursor-pointer transition-all hover:border-[#38bdf8] hover:bg-[#38bdf8]/5 min-h-[120px]">
                    @if($isUploading)
                        <span class="loading-spinner">Caricamento in corso…</span>
                    @else
                        <svg class="w-8 h-8 text-[#38bdf8]/80" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                        <span>Trascina qui il file</span>
                        <span class="text-xs text-[#94a3b8]">PDF, TXT o MD · max 10 MB</span>
                    @endif
                    <input type="file" wire:model="documentFile" accept=".pdf,.txt,.md" class="hidden" wire:loading.attr="disabled">
                </label>
                @error('documentFile') <div class="text-red-400 text-xs mt-1">{{ $message }}</div> @enderror
            </div>

            <div class="p-4 border-b border-[#2a344d] flex-1 overflow-auto">
                <div class="text-xs uppercase tracking-widest text-[#94a3b8] mb-3 font-semibold">Documenti indicizzati</div>
                @if(count($documents))
                    <div class="flex flex-col gap-2">
                        @foreach($documents as $doc)
                            <div class="flex items-center justify-between p-2.5 bg-[#0b0f19] border rounded-lg cursor-pointer hover:border-[#38bdf8] {{ $selectedDocumentId === $doc['document_id'] ? 'border-[#38bdf8] bg-[#38bdf8]/8' : 'border-[#2a344d]' }}">
                                <div wire:click="selectDocument('{{ $doc['document_id'] }}')" class="flex flex-col overflow-hidden flex-1">
                                    <strong class="text-sm truncate">{{ $doc['filename'] }}</strong>
                                    <span class="text-xs text-[#94a3b8]">{{ $doc['num_chunks'] }} chunk · {{ substr($doc['document_id'], 0, 8) }}</span>
                                </div>
                                <button wire:click="deleteDocument('{{ $doc['document_id'] }}')" wire:confirm="Eliminare questo documento?" class="text-[#94a3b8] hover:text-red-400 p-1">🗑</button>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-xs text-[#94a3b8]">Nessun documento caricato.</div>
                @endif
            </div>

            <div class="p-4">
                <div class="text-xs uppercase tracking-widest text-[#94a3b8] mb-3 font-semibold">Filtro attivo</div>
                <div class="text-xs text-[#94a3b8]">{{ $selectedDocumentId ? 'Documento: ' . substr($selectedDocumentId, 0, 8) . '…' : 'Tutti i documenti' }}</div>
                @if($selectedDocumentId)
                    <button wire:click="clearFilter" class="mt-2 w-full py-1.5 text-sm border border-[#2a344d] rounded-md bg-[#1e2639]">Rimuovi filtro</button>
                @endif
            </div>
        </aside>

        {{-- Overlay mobile --}}
        @if($panelOpen)
            <div class="fixed inset-0 bg-black/50 z-24 lg:hidden" wire:click="togglePanel"></div>
        @endif

        {{-- Main chat area --}}
        <main class="flex-1 flex flex-col min-w-0">
            <div class="flex-1 overflow-y-auto p-5 flex flex-col gap-4" id="chat-container">
                @if(!$reply && !$isLoading)
                    <div class="m-auto text-center text-[#94a3b8]">
                        <h2 class="font-medium text-lg mb-2">Benvenuto nella demo RAG</h2>
                        <p>Carica un documento e inizia a fare domande in linguaggio naturale.</p>
                    </div>
                @endif

                @foreach($previousMessages ?? [] as $msg)
                    {{-- Previous messages (if we add history loading) --}}
                @endforeach

                @if($reply)
                    <div class="flex gap-3 max-w-[85%] self-start animate-fade-in" wire:key="reply">
                        <div class="w-8 h-8 rounded-full bg-[#818cf8] flex items-center justify-center text-xs font-bold text-[#020617] shrink-0">AI</div>
                        <div class="bg-[#1e2639] border border-[#2a344d] rounded-xl p-4 leading-relaxed prose-a:text-[#38bdf8] prose-strong:text-[#38bdf8] prose-code:bg-[#94a3b8]/15 prose-code:px-1 prose-code:py-0.5 prose-code:rounded prose-code:text-sm prose-pre:bg-[#0b0f19] prose-pre:border prose-pre:border-[#2a344d] prose-pre:rounded-lg prose-pre:p-3">
                            {!! $replyHtml !!}
                        </div>
                    </div>
                @endif

                @if($isLoading)
                    <div class="flex gap-3 max-w-[85%] self-start animate-fade-in" wire:key="loading">
                        <div class="w-8 h-8 rounded-full bg-[#818cf8] flex items-center justify-center text-xs font-bold text-[#020617] shrink-0">AI</div>
                        <div class="bg-[#1e2639] border border-[#2a344d] rounded-xl p-4 text-[#94a3b8] text-sm">
                            <span class="inline-flex items-center gap-1.5"><span class="animate-spin inline-block">◠</span> Sto pensando…</span>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Trace panel --}}
            @if(count($trace))
                <div class="bg-[#151b2b] border-y border-[#2a344d] px-5 py-3 text-sm max-h-44 overflow-y-auto">
                    <div class="text-xs uppercase tracking-widest text-[#94a3b8] mb-1 font-semibold">Trace</div>
                    @foreach($trace as $step)
                        @php $status = $step['status'] ?? ''; @endphp
                        <div class="flex items-center gap-2 py-0.5 text-sm {{ $status === 'done' ? 'text-[#22c55e]' : ($status === 'down' ? 'text-[#ef4444]' : ($status === 'active' ? 'text-white' : 'text-[#94a3b8]')) }}">
                            <span>{{ $status === 'done' ? '●' : ($status === 'down' ? '✕' : ($status === 'active' ? '◐' : '○')) }}</span>
                            <span>{{ $step['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="border-t border-[#2a344d] p-4 bg-[#151b2b] flex gap-3 items-end">
                <textarea
                    wire:model="message"
                    wire:keydown.enter.prevent="sendMessage"
                    rows="1"
                    placeholder="Scrivi una domanda…"
                    class="flex-1 bg-[#0b0f19] border border-[#2a344d] rounded-lg px-4 py-3 text-white font-sans text-sm resize-none min-h-[48px] max-h-[160px] focus:outline-none focus:border-[#38bdf8] focus:ring-2 focus:ring-[#38bdf8]/10"
                    x-data
                    x-init="() => {}"
                    x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 160) + 'px'"
                    :disabled="$wire.isLoading"
                ></textarea>
                <button
                    wire:click="sendMessage"
                    wire:loading.attr="disabled"
                    class="bg-gradient-to-r from-[#38bdf8] to-[#818cf8] text-[#020617] font-bold px-5 py-3 rounded-lg text-sm disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    Invia
                </button>
            </div>
        </main>

        {{-- Right panel --}}
        @if($panelOpen)
            <aside class="w-[340px] bg-[#151b2b] border-l border-[#2a344d] max-lg:w-full max-lg:fixed max-lg:right-0 max-lg:top-14 max-lg:bottom-0 max-lg:z-25 p-4 overflow-y-auto">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex gap-2">
                        <button class="tab-btn {{ $activeTab === 'sources' ? 'tab-active' : '' }}" wire:click="setTab('sources')">Sorgenti</button>
                        <button class="tab-btn {{ $activeTab === 'system' ? 'tab-active' : '' }}" wire:click="setTab('system')">Sistema</button>
                    </div>
                    <button wire:click="togglePanel" class="p-1 text-[#94a3b8] hover:text-white">✕</button>
                </div>

                @if($activeTab === 'sources')
                    @if(count($sources))
                        @foreach($sources as $i => $src)
                            <div class="bg-[#0b0f19] border border-[#2a344d] rounded-lg p-3 mb-3 text-sm">
                                <div class="flex justify-between items-center mb-1.5">
                                    <span class="text-[#38bdf8] font-bold text-xs">#{{ $i + 1 }} score {{ number_format($src['score'] ?? 0, 3) }}</span>
                                    <span class="text-xs text-[#94a3b8]">{{ $src['filename'] ?? '' }}</span>
                                </div>
                                <div class="text-[#94a3b8]">{{ \Illuminate\Support\Str::limit($src['text'] ?? '', 300) }}</div>
                            </div>
                        @endforeach
                    @else
                        <div class="text-xs text-[#94a3b8]">Nessuna sorgente da mostrare.</div>
                    @endif
                @else
                    @if(count($stats))
                        {{-- Database --}}
                        <div class="detail-card">
                            <div class="flex items-center gap-1.5 font-semibold text-sm mb-1.5">
                                <span class="w-2 h-2 rounded-full {{ ($stats['qdrant']['status'] ?? '') === 'up' ? 'bg-[#22c55e]' : 'bg-[#ef4444]' }}"></span>
                                Database PostgreSQL
                            </div>
                            @php $q = $stats['qdrant'] ?? []; @endphp
                            <div class="flex justify-between text-xs text-[#94a3b8] py-0.5"><span>Stato</span><span class="text-white font-medium">{{ $q['status'] ?? '—' }}</span></div>
                            <div class="flex justify-between text-xs text-[#94a3b8] py-0.5"><span>Host</span><span class="text-white font-medium">{{ $q['host'] ?? '—' }}:{{ $q['port'] ?? '' }}</span></div>
                            <div class="flex justify-between text-xs text-[#94a3b8] py-0.5"><span>Collection</span><span class="text-white font-medium">{{ $q['collection_name'] ?? '—' }}</span></div>
                            <div class="flex justify-between text-xs text-[#94a3b8] py-0.5"><span>Vettori</span><span class="text-white font-medium">{{ ($q['vector_size'] ?? '—') }}d · {{ $q['distance'] ?? '—' }}</span></div>
                            <div class="flex justify-between text-xs text-[#94a3b8] py-0.5"><span>Punti / Doc</span><span class="text-white font-medium">{{ $q['points_count'] ?? '—' }} / {{ $q['documents_count'] ?? '—' }}</span></div>
                        </div>

                        {{-- Redis --}}
                        @php $r = $stats['redis'] ?? []; @endphp
                        <div class="detail-card">
                            <div class="flex items-center gap-1.5 font-semibold text-sm mb-1.5">
                                <span class="w-2 h-2 rounded-full {{ ($r['status'] ?? '') === 'up' ? 'bg-[#22c55e]' : 'bg-[#ef4444]' }}"></span>
                                Redis
                            </div>
                            <div class="flex justify-between text-xs text-[#94a3b8] py-0.5"><span>Stato</span><span class="text-white font-medium">{{ $r['status'] ?? '—' }}</span></div>
                            <div class="flex justify-between text-xs text-[#94a3b8] py-0.5"><span>Host</span><span class="text-white font-medium">{{ $r['host'] ?? '—' }}:{{ $r['port'] ?? '' }}</span></div>
                        </div>

                        {{-- FastAPI --}}
                        @php $f = $stats['fastapi'] ?? []; @endphp
                        <div class="detail-card">
                            <div class="flex items-center gap-1.5 font-semibold text-sm mb-1.5">
                                <span class="w-2 h-2 rounded-full {{ ($f['status'] ?? '') === 'up' ? 'bg-[#22c55e]' : 'bg-[#ef4444]' }}"></span>
                                FastAPI Engine
                            </div>
                            <div class="flex justify-between text-xs text-[#94a3b8] py-0.5"><span>Stato</span><span class="text-white font-medium">{{ $f['status'] ?? '—' }}</span></div>
                            <div class="flex justify-between text-xs text-[#94a3b8] py-0.5"><span>Versione</span><span class="text-white font-medium">{{ $f['version'] ?? '—' }}</span></div>
                            <div class="flex justify-between text-xs text-[#94a3b8] py-0.5"><span>Embedding</span><span class="text-white font-medium">{{ $f['embedding_model'] ?? '—' }}</span></div>
                            <div class="flex justify-between text-xs text-[#94a3b8] py-0.5"><span>Dim vettore</span><span class="text-white font-medium">{{ $f['embedding_dim'] ?? '—' }}</span></div>
                            <div class="flex justify-between text-xs text-[#94a3b8] py-0.5"><span>Chunk / overlap</span><span class="text-white font-medium">{{ $f['chunk_size'] ?? '—' }} / {{ $f['chunk_overlap'] ?? '—' }}</span></div>
                            <div class="flex justify-between text-xs text-[#94a3b8] py-0.5"><span>LLM</span><span class="text-white font-medium">{{ $f['llm_provider'] ?? '—' }} / {{ $f['llm_model'] ?? '—' }}</span></div>
                            <div class="flex justify-between text-xs text-[#94a3b8] py-0.5"><span>LLM status</span><span class="text-white font-medium">{{ $f['llm_status'] ?? '—' }}</span></div>
                        </div>
                    @else
                        <div class="text-xs text-[#94a3b8]">Caricamento dettagli sistema…</div>
                    @endif
                @endif
            </aside>
        @endif
    </div>
</div>
