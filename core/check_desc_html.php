<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$p = App\Models\Product::find(89);
echo "DESC:\n" . $p->description . "\n\n";
echo "EXTRA:\n";
print_r($p->extra_descriptions);
