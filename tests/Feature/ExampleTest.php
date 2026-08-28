<?php

test('guests see the DigitoFina landing page', function () {
    $response = $this->get('/');

    $response
        ->assertSuccessful()
        ->assertSee('Run your loan business')
        ->assertSee('What the owner gets')
        ->assertSee(route('login'));
});
