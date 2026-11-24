<?php
require_once __DIR__ . '/app_config.php';

function defaultSharedInventory(): array
{
    return [
        ['name' => 'Pandesal', 'stock' => 120],
        ['name' => 'Ensaymada', 'stock' => 42],
        ['name' => 'Spanish Bread', 'stock' => 84],
        ['name' => 'Hopia', 'stock' => 28],
        ['name' => 'Ube Bread', 'stock' => 67],
        ['name' => 'Cheese Roll', 'stock' => 55],
        ['name' => 'Monay', 'stock' => 19],
        ['name' => 'Pan de Coco', 'stock' => 30],
        ['name' => 'Putok (Star Bread)', 'stock' => 30],
        ['name' => 'Pan de Monggo', 'stock' => 30],
        ['name' => 'Bicho-Bicho', 'stock' => 30],
        ['name' => 'Pinagong', 'stock' => 30],
        ['name' => 'Pan de Leche', 'stock' => 30],
        ['name' => 'Pianono', 'stock' => 30],
    ];
}

function sharedInventoryPath(): string
{
    return appDataDir() . '/bread_inventory.json';
}

function updateSharedInventory(callable $update): mixed
{
    $handle = @fopen(sharedInventoryPath(), 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the inventory file.');
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('Unable to lock the inventory file.');
    }

    try {
        rewind($handle);
        $contents = stream_get_contents($handle);
        if ($contents === false) {
            throw new RuntimeException('Unable to read the inventory file.');
        }
        if ($contents === '') {
            $inventory = defaultSharedInventory();
        } else {
            $inventory = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($inventory)) {
                throw new RuntimeException('The inventory file has an invalid format.');
            }
        }

        $inventory = array_values(array_filter($inventory, 'is_array'));
        $result = $update($inventory);
        $encoded = json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($contents !== $encoded) {
            rewind($handle);
            if (fwrite($handle, $encoded) !== strlen($encoded) || !ftruncate($handle, strlen($encoded)) || !fflush($handle)) {
                throw new RuntimeException('Unable to save the inventory file.');
            }
        }

        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function loadSharedInventory(): array
{
    return updateSharedInventory(static function (array &$inventory): array {
        $existingNames = [];
        foreach ($inventory as &$item) {
            $item['name'] = trim((string)($item['name'] ?? ''));
            $item['stock'] = max(0, (int)($item['stock'] ?? 0));
            $item['status'] = sharedInventoryStatus($item['stock']);
            if ($item['name'] !== '') {
                $existingNames[$item['name']] = true;
            }
        }
        unset($item);

        foreach (defaultSharedInventory() as $defaultItem) {
            if (!isset($existingNames[$defaultItem['name']])) {
                $defaultItem['status'] = sharedInventoryStatus($defaultItem['stock']);
                $inventory[] = $defaultItem;
            }
        }

        return $inventory;
    });
}

function sharedInventoryStatus(int $stock): string
{
    if ($stock <= 0) {
        return 'Out of Stock';
    }
    if ($stock <= 10) {
        return 'Low';
    }

    return 'Healthy';
}

function adjustSharedInventoryStock(string $name, int $change): bool
{
    return updateSharedInventory(static function (array &$inventory) use ($name, $change): bool {
        foreach ($inventory as &$item) {
            if (($item['name'] ?? '') === $name) {
                $stock = max(0, (int)($item['stock'] ?? 0));
                if ($stock + $change < 0) {
                    unset($item);
                    return false;
                }
                $item['stock'] = $stock + $change;
                $item['status'] = sharedInventoryStatus($item['stock']);
                unset($item);
                return true;
            }
        }
        unset($item);

        return false;
    });
}

function updateSharedInventoryItem(string $oldName, string $newName, int $stock): array
{
    return updateSharedInventory(static function (array &$inventory) use ($oldName, $newName, $stock): array {
        $found = false;
        foreach ($inventory as $item) {
            if (($item['name'] ?? '') === $newName && $newName !== $oldName) {
                throw new InvalidArgumentException('An inventory item with that name already exists.');
            }
        }
        foreach ($inventory as &$item) {
            if (($item['name'] ?? '') === $oldName) {
                $item['name'] = $newName;
                $item['stock'] = $stock;
                $item['status'] = sharedInventoryStatus($stock);
                $found = true;
                break;
            }
        }
        unset($item);
        if (!$found) {
            throw new InvalidArgumentException('The inventory item could not be found.');
        }

        return $inventory;
    });
}
