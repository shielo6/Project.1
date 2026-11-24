<?php
require_once __DIR__ . '/app_config.php';

function debtRecordsPath(): string
{
    return appDataDir() . '/utang_records.json';
}

function loadDebtRecords(): array
{
    $path = debtRecordsPath();
    if (!file_exists($path)) {
        return [];
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the debt records file.');
    }
    if (!flock($handle, LOCK_SH)) {
        fclose($handle);
        throw new RuntimeException('Unable to lock the debt records file.');
    }

    try {
        $contents = stream_get_contents($handle);
        if ($contents === false) {
            throw new RuntimeException('Unable to read the debt records file.');
        }
        if ($contents === '') {
            return [];
        }

        $records = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($records)) {
            throw new RuntimeException('The debt records file has an invalid format.');
        }

        return array_values(array_filter($records, 'is_array'));
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function updateDebtRecords(callable $update): mixed
{
    $handle = @fopen(debtRecordsPath(), 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the debt records file for writing.');
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('Unable to lock the debt records file for writing.');
    }

    try {
        rewind($handle);
        $contents = stream_get_contents($handle);
        if ($contents === false) {
            throw new RuntimeException('Unable to read the debt records file for writing.');
        }
        $records = $contents === '' ? [] : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($records)) {
            throw new RuntimeException('The debt records file has an invalid format.');
        }
        $records = array_values(array_filter($records, 'is_array'));

        $result = $update($records);
        $encoded = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        rewind($handle);
        if (fwrite($handle, $encoded) !== strlen($encoded) || !ftruncate($handle, strlen($encoded)) || !fflush($handle)) {
            throw new RuntimeException('Unable to save the debt records file.');
        }

        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function addDebtRecord(string $name, int $amountCents, string $note): array
{
    $record = [
        'id' => bin2hex(random_bytes(16)),
        'name' => $name,
        'amountCents' => $amountCents,
        'note' => $note,
        'createdAt' => date(DATE_ATOM),
        'status' => 'unpaid',
    ];

    updateDebtRecords(static function (array &$records) use ($record): void {
        array_unshift($records, $record);
    });

    return $record;
}

function markDebtRecordPaid(string $id): bool
{
    return updateDebtRecords(static function (array &$records) use ($id): bool {
        foreach ($records as &$record) {
            if (($record['id'] ?? null) === $id && ($record['status'] ?? null) === 'unpaid') {
                $record['status'] = 'paid';
                unset($record);
                return true;
            }
        }
        unset($record);

        return false;
    });
}
