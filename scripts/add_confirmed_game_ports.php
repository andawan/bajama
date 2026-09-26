<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Traffic\TrafficCatalog;

$db->beginTransaction();
try {
    $catalog = new TrafficCatalog($db);
    $categoryId = $catalog->ensureCategory('game', 'Game', 'Domain game dan port yang dinyatakan dalam dokumentasi vendor.');
    $robloxSource = 'https://en.help.roblox.com/hc/en-us/articles/203312880-General-Connection-Problems';
    $activisionSource = 'https://support.activision.com/articles/ports-used-for-call-of-duty-games';
    $portForwardSource = 'https://portforward.com/genshin-impact/';
    $items = [
        ['49152-65535', 'UDP', 'Roblox client UDP port range (official Roblox support).', $robloxSource, 100],
        ['65010', 'TCP', 'Call of Duty: Mobile lobby (Activision support).', $activisionSource, 100],
        ['65050', 'TCP', 'Call of Duty: Mobile chat (Activision support).', $activisionSource, 100],
        ['22101-22102', 'UDP', 'Genshin Impact PC UDP range (Port Forward community listing; not a HoYoverse-published requirement).', $portForwardSource, 75],
        ['42472', 'UDP', 'Genshin Impact PC UDP port (Port Forward community listing; not a HoYoverse-published requirement).', $portForwardSource, 75],
        ['42472', 'TCP', 'Genshin Impact PC TCP port (Port Forward community listing; not a HoYoverse-published requirement).', $portForwardSource, 75],
    ];

    foreach ([
        ['roblox.com', 'Roblox official service domain', $robloxSource],
        ['rbxcdn.com', 'Roblox official content delivery domain', $robloxSource],
        ['callofduty.com', 'Call of Duty official game domain', $activisionSource],
        ['activision.com', 'Activision official publisher domain', $activisionSource],
        ['genshin.hoyoverse.com', 'Genshin Impact official service domain', 'https://support.hoyoverse.com/hc/en-us/sections/49608660600217-Genshin-Impact'],
        ['hoyoverse.com', 'HoYoverse official service domain', 'https://support.hoyoverse.com/hc/en-us/'],
    ] as [$domain, $description, $source]) {
        $catalog->upsert([
            'category_id' => $categoryId,
            'name' => $domain,
            'entry_type' => 'DOMAIN',
            'value' => $domain,
            'protocol' => 'ANY',
            'source_type' => 'IMPORT',
            'confidence' => 95,
            'description' => $description,
            'metadata' => [
                'seed_revision' => '2026-09-26-game-ports',
                'source_urls' => [$source],
            ],
        ]);
    }

    foreach ($items as [$range, $protocol, $description, $source, $confidence]) {
        $catalog->upsert([
            'category_id' => $categoryId,
            'name' => 'Game port ' . $range . '/' . $protocol,
            'entry_type' => 'PORT',
            'value' => $range,
            'port' => (int)explode('-', $range, 2)[0],
            'protocol' => $protocol,
            'source_type' => 'IMPORT',
            'confidence' => $confidence,
            'description' => $description,
            'metadata' => [
                'seed_revision' => '2026-09-26-game-ports',
                'source_urls' => [$source],
                'scope' => strpos($description, 'Genshin Impact') === 0
                    ? 'PC listing; platform-specific ports may differ.'
                    : 'Client port range as stated in support reference.',
            ],
        ]);
    }

    // Activision's UDP 7500-8000 for COD Mobile is already covered by the
    // existing 7000-8000 UDP game range seeded from Riot; record both sources
    // on that shared effective range rather than creating a duplicate mangle.
    $sharedRange = $db->prepare(
        'SELECT id, description, metadata FROM traffic_catalog
         WHERE category_id = ? AND entry_type = "PORT" AND normalized_value = "7000-8000" AND protocol = "UDP"
         LIMIT 1'
    );
    $sharedRange->execute([$categoryId]);
    $existingRange = $sharedRange->fetch(PDO::FETCH_ASSOC);
    if ($existingRange) {
        $metadata = json_decode((string)$existingRange['metadata'], true);
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $sources = is_array($metadata['source_urls'] ?? null) ? $metadata['source_urls'] : [];
        $metadata['source_urls'] = array_values(array_unique(array_merge($sources, [$activisionSource])));
        $metadata['also_covers'] = ['Call of Duty: Mobile UDP 7500-8000 is a subset of this existing range.'];
        $update = $db->prepare('UPDATE traffic_catalog SET description = ?, metadata = ? WHERE id = ?');
        $update->execute([
            rtrim((string)$existingRange['description'], '.') . '; also covers Call of Duty: Mobile UDP 7500-8000 (Activision support).',
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            (int)$existingRange['id'],
        ]);
    }

    $db->commit();
    echo "Game domains and sourced port entries saved.\n";
    echo "Ports inserted/updated: " . count($items) . "\n";
    echo "COD Mobile UDP 7500-8000: covered by existing 7000-8000 UDP rule and provenance recorded.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Game port update rolled back: ' . $e->getMessage() . "\n");
    exit(1);
}
