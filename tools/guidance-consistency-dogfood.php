<?php

declare(strict_types=1);

use voku\AgentLoop\Dogfood\GuidanceConsistencyDogfood;

require dirname(__DIR__) . '/vendor/autoload.php';

(new GuidanceConsistencyDogfood(dirname(__DIR__)))->run();
