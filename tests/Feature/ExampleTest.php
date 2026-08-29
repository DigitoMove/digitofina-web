<?php

test('guests see the DigitoFina landing page', function () {
    $response = $this->get('/');

    $response
        ->assertSuccessful()
        ->assertSee('Let clients pay by mobile money')
        ->assertSee('Collect funds directly')
        ->assertSee('What the owner gets')
        ->assertSee(route('login'));
});
