<?php

namespace App\Livewire;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class Demo extends Component
{
    use WithFileUploads;

    #[Validate('required|string|min:1|max:4096')]
    public string $message = '';

    public string $reply = '';

    public array $sources = [];

    public array $documents = [];

    public ?string $selectedDocumentId = null;

    public array $trace = [];

    public array $traceSteps = [];

    public array $stats = [];

    public bool $isLoading = false;

    public string $activeTab = 'sources';

    public bool $panelOpen = false;

    public string $healthStatus = 'checking…';

    public string $sessionId = '';

    public bool $isUploading = false;

    #[Validate('file|mimes:pdf,txt,md|max:10240')]
    public $documentFile = null;

    private function engineUrl(): string
    {
        $url = rtrim((string) config('app.fastapi_internal_url', ''), '/');

        return $url ?: 'http://ai_fastapi_engine:8000';
    }

    private function httpClient(): \Illuminate\Http\Client\PendingRequest
    {
        return \Illuminate\Support\Facades\Http::withHeader('X-Correlation-ID', app('correlation_id'));
    }

    public function mount(): void
    {
        $this->sessionId = session()->get('rag_session_id', (string) Str::uuid());
        session()->put('rag_session_id', $this->sessionId);
        $this->loadDocuments();
        $this->refreshStats();
        $this->checkHealth();
    }

    public function checkHealth(): void
    {
        try {
            $res = $this->httpClient()->timeout(5)->get("{$this->engineUrl()}/");
            $this->healthStatus = $res->successful() ? 'Stack online' : 'Stack degradato';
        } catch (\Throwable) {
            $this->healthStatus = 'Stack offline';
        }
    }

    public function refreshStats(): void
    {
        try {
            $res = $this->httpClient()->timeout(10)->get("{$this->engineUrl()}/api/v1/stats");
            if ($res->successful()) {
                $this->stats = $res->json();
            }
        } catch (\Throwable) {
        }
    }

    public function loadDocuments(): void
    {
        try {
            $res = $this->httpClient()->timeout(15)->get("{$this->engineUrl()}/api/v1/documents");
            $data = $res->json();
            $this->documents = $data['documents'] ?? [];
        } catch (\Throwable) {
        }
    }

    public function deleteDocument(string $documentId): void
    {
        try {
            $this->httpClient()->timeout(15)->delete("{$this->engineUrl()}/api/v1/documents/{$documentId}");
            if ($this->selectedDocumentId === $documentId) {
                $this->selectedDocumentId = null;
            }
            $this->loadDocuments();
            $this->refreshStats();
        } catch (\Throwable) {
        }
    }

    public function updatedDocumentFile(): void
    {
        $this->uploadDocument();
    }

    public function uploadDocument(): void
    {
        $this->validate(['documentFile' => 'file|mimes:pdf,txt,md|max:10240']);

        $this->isUploading = true;

        $this->trace = [
            ['label' => "Ricevuto {$this->documentFile->getClientOriginalName()}", 'status' => 'done'],
            ['label' => 'Estrazione testo…', 'status' => 'active'],
            ['label' => 'Chunking & embedding…', 'status' => null],
            ['label' => 'Indicizzazione Qdrant…', 'status' => null],
        ];

        try {
            $res = $this->httpClient()->timeout(120)
                ->attach(
                    'file',
                    file_get_contents($this->documentFile->getRealPath()),
                    $this->documentFile->getClientOriginalName()
                )
                ->post("{$this->engineUrl()}/api/v1/documents");

            $data = $res->json();

            if ($res->successful() && isset($data['document_id'])) {
                $this->trace = [
                    ['label' => "Ricevuto {$this->documentFile->getClientOriginalName()}", 'status' => 'done'],
                    ['label' => 'Testo estratto', 'status' => 'done'],
                    ['label' => "{$data['num_chunks']} chunk · modello {$data['embedding_model']} ({$data['embedding_dim']}d)", 'status' => 'done'],
                    ['label' => "Upsert in Qdrant ({$data['collection_name']})", 'status' => 'done'],
                ];
                $this->loadDocuments();
                $this->refreshStats();
            } else {
                $this->trace = [
                    ['label' => $data['error'] ?? 'Errore caricamento', 'status' => 'down'],
                ];
            }
        } catch (\Throwable $e) {
            $this->trace = [
                ['label' => "Errore: {$e->getMessage()}", 'status' => 'down'],
            ];
        } finally {
            $this->isUploading = false;
            $this->documentFile = null;
        }
    }

    public function sendMessage(): void
    {
        $this->validate(['message' => 'required|string|min:1|max:4096']);

        $query = trim($this->message);
        $this->reply = '';
        $this->isLoading = true;
        $this->message = '';

        $this->trace = [
            ['label' => 'Embedding della domanda…', 'status' => 'active'],
            ['label' => 'Ricerca vettoriale su Qdrant…', 'status' => null],
            ['label' => 'Costruzione prompt con contesto…', 'status' => null],
            ['label' => 'Chiamata LLM…', 'status' => null],
        ];

        try {
            $payload = [
                'message' => $query,
                'session_id' => $this->sessionId,
            ];
            if ($this->selectedDocumentId) {
                $payload['document_id'] = $this->selectedDocumentId;
            }

            $res = $this->httpClient()->timeout(120)
                ->acceptJson()
                ->asJson()
                ->post("{$this->engineUrl()}/api/v1/chat", $payload);

            $data = $res->json();

            if ($res->successful() && isset($data['reply'])) {
                $this->reply = $data['reply'];
                $this->sources = $data['sources'] ?? [];
                if (isset($data['session_id'])) {
                    $this->sessionId = $data['session_id'];
                    session()->put('rag_session_id', $this->sessionId);
                }

                $this->trace = [
                    ['label' => 'Embedding della domanda', 'status' => 'done'],
                    ['label' => "Retrieved {$data['retrieved_chunks']} chunk da Qdrant", 'status' => 'done'],
                    ['label' => "Prompt costruito con {$data['prompt_tokens']} token", 'status' => 'done'],
                    ['label' => "Risposta da {$data['model']} ({$data['completion_tokens']} token)", 'status' => 'done'],
                ];
                $this->refreshStats();
            } else {
                $this->reply = 'Errore: ' . ($data['error'] ?? 'risposta non valida');
                $this->trace = [
                    ['label' => $data['error'] ?? 'Errore risposta', 'status' => 'down'],
                ];
            }
        } catch (\Throwable $e) {
            $this->reply = 'Errore di rete: ' . $e->getMessage();
            $this->trace = [
                ['label' => "Errore di rete: {$e->getMessage()}", 'status' => 'down'],
            ];
        } finally {
            $this->isLoading = false;
        }
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function togglePanel(): void
    {
        $this->panelOpen = ! $this->panelOpen;
    }

    public function selectDocument(?string $documentId): void
    {
        $this->selectedDocumentId = $documentId;
    }

    public function clearFilter(): void
    {
        $this->selectedDocumentId = null;
        $this->loadDocuments();
    }

    public function render()
    {
        return view('livewire.demo', [
            'replyHtml' => $this->reply ? Str::markdown($this->reply, ['html_input' => 'strip']) : '',
        ])->layout('layouts.app', ['title' => 'AI Enterprise — RAG Demo']);
    }
}
