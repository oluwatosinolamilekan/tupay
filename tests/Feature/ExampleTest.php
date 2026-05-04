<?php

it('returns a successful response from the home page', function (): void {
    $this->get('/')->assertStatus(200);
});
