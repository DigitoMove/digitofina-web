<?php

test('login page includes search and link preview metadata', function () {
    $response = $this->get('/login');

    $response
        ->assertSuccessful()
        ->assertSee('<title>Secure Login | DigitoFina</title>', false)
        ->assertSee('<meta name="description" content="Sign in to DigitoFina', false)
        ->assertSee('<meta property="og:image"', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
        ->assertSee('<script type="application/ld+json">', false);
});

test('link preview image is generated as cacheable svg', function () {
    $response = $this->get(route('link-preview', [
        'title' => 'Client Management',
        'description' => 'Register and manage client profiles.',
    ]));

    $response
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')
        ->assertHeader('Cache-Control', 'max-age=86400, public')
        ->assertSee('<svg', false)
        ->assertSee('Client Management')
        ->assertSee('Register and manage client profiles.');
});
