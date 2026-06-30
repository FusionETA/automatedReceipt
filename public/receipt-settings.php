<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Bootstrap;

Bootstrap::init();

header('Location: ' . Bootstrap::url('/bank-accounts.php'));
exit;
