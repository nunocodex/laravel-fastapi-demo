<?php

use App\Livewire\Demo;
use Livewire\Livewire;

it('renders the demo page successfully', function () {
    $response = $this->get('/demo');

    $response->assertStatus(200);
    $response->assertSee('AI Enterprise');
    $response->assertSee('RAG Demo');
});

it('can mount the demo component', function () {
    Livewire::test(Demo::class)
        ->assertSee('Benvenuto nella demo RAG')
        ->assertSet('message', '')
        ->assertSet('isLoading', false);
});

it('shows empty state when no reply', function () {
    Livewire::test(Demo::class)
        ->assertSee('Carica un documento e inizia a fare domande');
});

it('validates message before sending', function () {
    Livewire::test(Demo::class)
        ->set('message', '')
        ->call('sendMessage')
        ->assertHasErrors(['message']);
});
