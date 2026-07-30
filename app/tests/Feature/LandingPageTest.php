<?php

test('landing page presents Monitor branding', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Monitor')
        ->assertSee('Know when your websites need attention.')
        ->assertSee('M9 19h5l3-7', false)
        ->assertDontSee('Laravel')
        ->assertDontSee('Deploy now');
});
