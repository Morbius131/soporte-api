<?php

namespace Tests\Feature;

use Tests\TestCase;

class PanelTest extends TestCase
{
    public function test_la_raiz_sirve_el_panel_web(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Panel de tickets')
            ->assertSee('panel/app.js', false);
    }
}
