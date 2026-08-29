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

test('link preview image is generated as a cacheable social png', function () {
    $response = $this->get(route('link-preview', [
        'title' => 'Client Management',
        'description' => 'Register and manage client profiles.',
    ]));

    $response
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'max-age=86400, public')
        ->assertHeader('Content-Disposition', 'inline; filename="digitofina-link-preview.png"');

    expect(substr($response->getContent(), 1, 3))->toBe('PNG');
});

test('landing page has focused mobile money search and share metadata', function () {
    $this->get('/')
        ->assertSuccessful()
        ->assertSee('<title>Mobile Money Collections for Loans and SACCOs | DigitoFina</title>', false)
        ->assertSee('Collect loan repayments, savings deposits and member contributions by mobile money')
        ->assertSee('<meta property="og:image:type" content="image/png">', false)
        ->assertSee('/link-preview.png?', false)
        ->assertSee('<meta name="twitter:image:alt"', false);
});
