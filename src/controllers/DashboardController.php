<?php

require_once __DIR__ . '/AppController.php';

class DashboardController extends AppController
{
    public function index($id = null)
    {
        $this->render('dashboard', [
            'title' => 'WDPAI - Dashboard',
        ]);
    }
}
