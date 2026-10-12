<?php

use App\Models\User;
use App\Services\CredentialsPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates a valid non-empty credentials PDF binary', function () {
    $service = app(CredentialsPdfService::class);
    $binary = $service->generate();

    expect($binary)->not->toBeEmpty()
        ->and(str_starts_with($binary, '%PDF'))->toBeTrue();
});

it('downloads credentials PDF via authenticated route', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('credentials.pdf'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});
