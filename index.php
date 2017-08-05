<?php

require(__DIR__ . '/vendor/autoload.php');

use Ryadnov\ZenMoney\Api\Auth\OAuth2;
use Ryadnov\ZenMoney\Scripts\ReminderRenewal;

$config = require(__DIR__ . '/config.php');
$token  = (new OAuth2($config))->getToken();

(new ReminderRenewal($token['access_token']))->run();
