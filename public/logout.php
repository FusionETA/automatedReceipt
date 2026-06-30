<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Bootstrap;
use App\Auth\Auth;

Bootstrap::init();
Auth::logout();

header('Location: ' . Bootstrap::url('/login.php'));
exit;