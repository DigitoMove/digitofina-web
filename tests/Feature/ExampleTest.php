<?php

test('guests see the DigitoFina landing page', function () {
    $response = $this->get('/');

    $response
        ->assertSuccessful()
        ->assertSee('Every loan. Every branch.')
        ->assertSee(route('login'));
});
