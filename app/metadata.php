<?php
declare(strict_types=1);

// Attachment filenames; author order is stored transactionally in AutorArtikel.Position.
function article_metadata(int $id): array
{
    return json_decode((string)@file_get_contents(APP_ROOT . '/.private/meta-' . $id . '.json'), true) ?: [];
}
function write_article_metadata(int $id, array $data): void
{
    $path = APP_ROOT . '/.private/meta-' . $id . '.json';
    $tmp = tempnam(APP_ROOT . '/.private', 'meta-');
    if ($tmp === false) throw new RuntimeException('Die Zusatzinformationen konnten nicht gespeichert werden.');
    try {
        if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false || !rename($tmp, $path)) {
            throw new RuntimeException('Die Zusatzinformationen konnten nicht gespeichert werden.');
        }
        chmod($path, 0660);
    } finally { if (is_file($tmp)) unlink($tmp); }
}
