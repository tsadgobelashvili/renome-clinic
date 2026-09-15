<?php

test('the application root redirects guests to ERP login', function () {
    $response = $this->get('/');

    $response->assertRedirect('/login');
});
