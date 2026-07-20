<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Machine;
use Illuminate\Support\Facades\File;

function safeFolderName($name) {
    $name = trim((string) $name);
    $name = preg_replace('/[\/\\\\:*?"<>|]+/u', '-', $name);
    $name = preg_replace('/\s+/u', ' ', $name);
    return $name ?: 'machine-' . time();
}

function collectImagesDeep($value, &$images) {
    if (!$value) return;

    if (is_string($value)) {
        if (
            preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $value) ||
            str_contains($value, 'machines/')
        ) {
            $images[] = $value;
        }
        return;
    }

    if (!is_array($value)) return;

    foreach ($value as $child) {
        collectImagesDeep($child, $images);
    }
}

foreach (Machine::all() as $machine) {
    $folderName = safeFolderName($machine->name);
    $targetDir = storage_path("app/public/machines-structured/{$folderName}");

    if (File::missing($targetDir)) {
        File::makeDirectory($targetDir, 0775, true);
    }

    $allImages = [];

    collectImagesDeep($machine->display_image, $allImages);
    collectImagesDeep($machine->images, $allImages);
    collectImagesDeep($machine->colors, $allImages);
    collectImagesDeep($machine->features, $allImages);

    $allImages = array_values(array_unique(array_filter($allImages)));

    $counter = 1;

    foreach ($allImages as $oldPath) {
        $relative = str_replace(['/storage/', 'storage/'], '', $oldPath);
        $relative = ltrim(parse_url($relative, PHP_URL_PATH) ?: $relative, '/');

        $oldFullPath = storage_path('app/public/' . $relative);

        if (File::missing($oldFullPath)) {
            echo "MISSING: {$machine->name} => {$oldPath}\n";
            continue;
        }

        $ext = pathinfo($oldFullPath, PATHINFO_EXTENSION) ?: 'jpg';
        $newName = $counter . '.' . $ext;
        $newFullPath = $targetDir . '/' . $newName;

        File::copy($oldFullPath, $newFullPath);

        echo "COPIED: {$machine->name} => {$newName}\n";

        $counter++;
    }
}
