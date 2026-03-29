<?php

require_once __DIR__ . '/AppController.php';

class SecurityController extends AppController
{
    public function login($id = null): void
    {
        $this->render('login', [
            'title' => 'WDPAI - Login',
        ]);
    }
}
