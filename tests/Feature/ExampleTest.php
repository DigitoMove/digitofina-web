<?php

test('guests are redirected to the DigitoFina login screen', function () {
    $response = $this->get('/');

    $response->assertRedirect('/login');
});
