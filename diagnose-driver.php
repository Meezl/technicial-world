<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Driver Diagnostic ===\n";
echo "Session driver config: " . config('session.driver') . "\n";
echo "Cache store config: " . config('cache.default') . "\n";
echo "Queue connection config: " . config('queue.default') . "\n";
echo "Maintenance driver config: " . config('app.maintenance.driver') . "\n";
echo "Maintenance store config: " . config('app.maintenance.store') . "\n";
echo "Hash driver config: " . config('hashing.driver') . "\n";
echo "\n";

$tests = [
    'SessionManager' => fn() => app('session')->driver(),
    'SessionManager(database)' => fn() => app('session')->driver('database'),
    'MaintenanceMode' => fn() => app(Illuminate\Foundation\MaintenanceModeManager::class)->driver(),
    'MaintenanceMode(database)' => fn() => app(Illuminate\Foundation\MaintenanceModeManager::class)->driver('database'),
    'HashManager' => fn() => app('hash')->driver(),
    'ChannelManager' => fn() => app(Illuminate\Notifications\ChannelManager::class)->driver('database'),
];

foreach ($tests as $name => $test) {
    try {
        $test();
        echo "$name: OK\n";
    } catch (Exception $e) {
        echo "$name: FAIL - " . $e->getMessage() . "\n";
    }
}
