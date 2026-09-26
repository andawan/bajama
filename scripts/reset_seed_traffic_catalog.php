<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Traffic\TrafficCatalog;

$options = getopt('', ['confirm-global-reset', 'backup:', 'dry-run']);
$backupPath = (string)($options['backup'] ?? '');
if ((!isset($options['confirm-global-reset']) && !isset($options['dry-run']))
    || $backupPath === '' || !is_readable($backupPath)) {
    fwrite(STDERR, "Usage: php scripts/reset_seed_traffic_catalog.php --confirm-global-reset --backup=/path/to/traffic_catalog-backup.json [--dry-run]\n");
    exit(2);
}
$backupData = json_decode((string)file_get_contents($backupPath), true);
if (!is_array($backupData)
    || !isset($backupData['traffic_categories'], $backupData['traffic_catalog'])
    || !is_array($backupData['traffic_categories'])
    || !is_array($backupData['traffic_catalog'])) {
    fwrite(STDERR, "Backup invalid: expected categories and catalog rows in JSON.\n");
    exit(2);
}

$blackmatrix = 'https://github.com/blackmatrix7/ios_rule_script';
$blackmatrixList = static fn(string $app): string => $blackmatrix . '/blob/master/rule/Surge/' . $app . '/' . $app . '.list';
$adguard = 'https://github.com/AdguardTeam/AdGuardSDNSFilter/blob/master/Filters/rules.txt';
$valvePorts = 'https://help.steampowered.com/en/faqs/view/2EA8-4D75-DA21-31EB';
$riotPorts = 'https://support.riotgames.com/en-us/league-of-legends/connectivity/advanced-connections-troubleshooting-guide';

$categories = [
    'streaming' => ['Streaming', 'Layanan video, musik, dan live streaming.'],
    'social' => ['Social Media', 'Situs dan layanan sosial resmi.'],
    'chat' => ['Chat', 'Situs dan layanan pesan resmi.'],
    'game' => ['Game', 'Domain game dan port yang dinyatakan dalam dokumentasi vendor.'],
    'banking' => ['Banking', 'Domain layanan resmi perbankan.'],
    'e-wallet' => ['E-Wallet', 'Domain layanan resmi dompet digital.'],
    'browser' => ['Browser / General', 'Domain browser dan layanan web umum.'],
    'infrastructure' => ['Infrastructure', 'Domain DNS, waktu, dan layanan konektivitas.'],
    'advertising' => ['Advertising', 'Domain iklan/tracker dari daftar DNS terpelihara.'],
    'content-creation' => ['Content Creation', 'Domain resmi layanan pembuatan konten.'],
    'e-commerce' => ['E Commerce', 'Domain toko daring dan marketplace.'],
    'applications' => ['Applications', 'Domain resmi toko aplikasi dan layanan aplikasi umum.'],
    'other' => ['Other / Lainnya', 'Domain layanan produktivitas umum yang tidak masuk kategori lain.'],
];

// Use only DNS-capable domain rules or official service homepages, never URL paths,
// process names, user-agent patterns, keywords, or stale IP ranges.
$domains = [
    'social' => [
        'facebook.com','fb.com','fbcdn.net','fbsbx.com','messenger.com','m.me','threads.net',
        'instagram.com','cdninstagram.com','instagr.am','ig.me',
        'tiktok.com','tiktokv.com','tiktokcdn.com','tiktokd.net','tiktokd.org','musical.ly',
        'x.com','twitter.com','twimg.com','t.co','reddit.com','redd.it',
    ],
    'streaming' => [
        'youtube.com','youtube-nocookie.com','youtubei.googleapis.com','googlevideo.com','ytimg.com','youtu.be','gvt1.com','gvt2.com',
        'netflix.com','netflix.net','nflxvideo.net','nflximg.net','nflximg.com','nflxso.net','nflxext.com',
        'twitch.tv','twitchcdn.net','jtvnw.net','live-video.net','ttvnw.net',
        'spotify.com','scdn.co','spotifycdn.com','disneyplus.com','hotstar.com','primevideo.com','amazonvideo.com',
        'viu.com','wetv.vip','iq.com','viki.com','dailymotion.com','vimeo.com',
    ],
    'chat' => [
        'whatsapp.com','whatsapp.net','wa.me','whatsapp.org',
        'telegram.org','telegram.me','telegram.com','t.me','telegram-cdn.org',
        'discord.com','discord.gg','discordapp.com','discordapp.net','discordapp.io','discordcdn.com',
        'signal.org','signal.me','line.me','line-apps.com',
        'wechat.com','weixin.com','weixin.qq.com','wx.qq.com','servicewechat.com','tenpay.com','wechatpay.com','qpic.cn','gtimg.com',
    ],
    'game' => [
        'steampowered.com','steamcommunity.com','steamgames.com','steamusercontent.com','steamcontent.com','steamstatic.com','steam-chat.com','steam-api.com','steamcdn-a.akamaihd.net','valvesoftware.com',
        'riotgames.com','leagueoflegends.com','playvalorant.com','valorant.com','pvp.net','teamfighttactics.leagueoflegends.com','wildrift.leagueoflegends.com',
        'freefiremobile.com','ff.garena.com','garena.com','mobilelegends.com','roblox.com','rbxcdn.com','epicgames.com','fortnite.com','pubg.com','pubgmobile.com','playstation.com','xbox.com',
    ],
    'banking' => [
        'bca.co.id','mybca.bca.co.id','mybcabisnis.bca.co.id','klikbca.com','ibank.klikbca.com','vpn.klikbca.com',
        'bri.co.id','ib.bri.co.id','bankmandiri.co.id','livin.bankmandiri.co.id','koprabymandiri.com',
        'bni.co.id','ibank.bni.co.id','wondr.bni.co.id','banksyariahindonesia.co.id','seabank.co.id','jago.com','cimbniaga.co.id',
    ],
    'e-wallet' => [
        'dana.id','a.m.dana.id','ovo.id','gopay.co.id','app.gopay.co.id','linkaja.id','cdn.linkaja.com','shopeepay.co.id','product.shopeepay.co.id',
    ],
    'browser' => [
        'google.com','google.co.id','chromium.org','googlechromelabs.github.io','mozilla.org','firefox.com','microsoft.com','edge.microsoft.com','opera.com','brave.com',
    ],
    'infrastructure' => [
        'dns.google','dns.adguard.com','cloudflare-dns.com','one.one.one.one','time.google.com','pool.ntp.org',
        'connectivitycheck.gstatic.com','connectivitycheck.android.com','android.googleapis.com','ocsp.apple.com','icloud.com','apple-dns.net',
    ],
    'advertising' => [
        'doubleclick.net','googlesyndication.com','googleadservices.com','adservice.google.com','app-measurement.com','admob.com',
        'applovin.com','adjust.com','appsflyer.com','unityads.unity3d.com','pangle.io','ads-twitter.com','ads.yahoo.com',
        'taboola.com','outbrain.com','criteo.com','adnxs.com','rubiconproject.com','openx.net','pubmatic.com','smartadserver.com',
        'scorecardresearch.com','quantserve.com','moatads.com','adsafeprotected.com','branch.io','doubleverify.com','innovid.com','tremorhub.com',
    ],
    'content-creation' => [
        'capcut.com','capcutapi.com','ibyteimg.com','canva.com','canva.cn','adobe.com','behance.net','figma.com','pinterest.com','pinterestcdn.com',
    ],
    'e-commerce' => [
        'shopee.co.id','shopee.com','tokopedia.com','bukalapak.com','lazada.co.id','lazada.com','blibli.com','aliexpress.com','amazon.com',
    ],
    'applications' => [
        'play.google.com','play.googleapis.com','googleplay.com','android.com','apps.apple.com','appstore.com','apple.com','itunes.apple.com',
        'microsoft.com','office.com','office365.com','zoom.us','slack.com','github.com','githubusercontent.com','dropbox.com','whatsapp.com',
    ],
    'other' => [
        'wikipedia.org','wikimedia.org','archive.org','stackoverflow.com','stackexchange.com','cloudflare.com','fast.com','speedtest.net',
    ],
];

$domainSources = [
    'social' => [$blackmatrixList('Facebook'), $blackmatrixList('Instagram'), $blackmatrixList('TikTok')],
    'streaming' => [$blackmatrixList('YouTube'), $blackmatrixList('Netflix'), $blackmatrixList('Twitch')],
    'chat' => [$blackmatrixList('Discord'), $blackmatrixList('WeChat'), 'https://www.whatsapp.com/'],
    'game' => [$blackmatrixList('Steam'), 'https://www.leagueoflegends.com/en-us/', 'https://www.freefiremobile.com/', 'https://www.mobilelegends.com/', 'https://www.roblox.com/'],
    'banking' => ['https://www.bca.co.id/', 'https://bri.co.id/', 'https://www.bankmandiri.co.id/', 'https://www.bni.co.id/', 'https://www.seabank.co.id/', 'https://jago.com/'],
    'e-wallet' => ['https://www.dana.id/', 'https://www.ovo.id/', 'https://gopay.co.id/', 'https://www.linkaja.id/', 'https://shopeepay.co.id/'],
    'browser' => [$blackmatrixList('Google'), 'https://www.mozilla.org/', 'https://www.microsoft.com/'],
    'infrastructure' => [$blackmatrixList('Google'), $blackmatrixList('Apple')],
    'advertising' => [$adguard],
    'content-creation' => ['https://www.capcut.com/', 'https://www.canva.com/', 'https://www.adobe.com/'],
    'e-commerce' => [$blackmatrixList('Shopee'), 'https://www.tokopedia.com/'],
    'applications' => [$blackmatrixList('Apple'), $blackmatrixList('Google'), 'https://www.microsoft.com/'],
    'other' => ['https://www.wikipedia.org/', 'https://www.archive.org/', 'https://stackoverflow.com/'],
];

$portSeeds = [
    // Valve's official Steam port requirements (game/client traffic only).
    ['27015-27050', 'TCP', $valvePorts, 'Steam client remote TCP ports'],
    ['27015-27050', 'UDP', $valvePorts, 'Steam client remote UDP ports'],
    ['27000-27250', 'UDP', $valvePorts, 'Steam game traffic'],
    ['27031-27036', 'UDP', $valvePorts, 'Steam Remote Play'],
    ['27036', 'TCP', $valvePorts, 'Steam Remote Play'],
    ['4380', 'UDP', $valvePorts, 'Steam client'],
    ['3478', 'UDP', $valvePorts, 'Steamworks P2P/voice'],
    ['4379', 'UDP', $valvePorts, 'Steamworks P2P/voice'],
    ['27014-27030', 'UDP', $valvePorts, 'Steamworks P2P/voice'],
    // Riot's official League of Legends port-forwarding table.
    ['5000-5500', 'UDP', $riotPorts, 'League of Legends game client'],
    ['7000-8000', 'UDP', $riotPorts, 'League of Legends game client'],
    ['8393-8400', 'TCP', $riotPorts, 'League of Legends patcher/Maestro'],
    ['2099', 'TCP', $riotPorts, 'League of Legends PVP.Net'],
    ['5223', 'TCP', $riotPorts, 'League of Legends PVP.Net'],
    ['5222', 'TCP', $riotPorts, 'League of Legends PVP.Net'],
    ['8088', 'TCP', $riotPorts, 'League of Legends spectator mode'],
    ['8088', 'UDP', $riotPorts, 'League of Legends spectator mode'],
];

$db->beginTransaction();
try {
    $catalog = new TrafficCatalog($db);
    $categoryIds = [];
    foreach ($categories as $slug => [$name, $description]) {
        $categoryIds[$slug] = $catalog->ensureCategory($slug, $name, $description);
    }

    $db->exec('DELETE FROM traffic_catalog');
    $inserted = 0;
    $seen = [];

    foreach ($domains as $slug => $values) {
        foreach (array_values(array_unique($values)) as $domain) {
            $key = $slug . '|DOMAIN|' . strtolower($domain);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $catalog->upsert([
                'category_id' => $categoryIds[$slug],
                'name' => $domain,
                'entry_type' => 'DOMAIN',
                'value' => $domain,
                'port' => 0,
                'protocol' => 'ANY',
                'source_type' => 'IMPORT',
                'confidence' => 95,
                'description' => 'Curated DNS domain seed; see source URLs in metadata.',
                'metadata' => [
                    'seed_revision' => '2026-09-26',
                    'source_urls' => $domainSources[$slug] ?? [],
                    'method' => 'DNS domain/host entries only; no keyword, URL path, IP or process rules imported.',
                ],
            ]);
            $inserted++;
        }
    }

    foreach ($portSeeds as [$range, $protocol, $sourceUrl, $description]) {
        $start = (int)explode('-', $range, 2)[0];
        $key = 'game|PORT|' . $range . '|' . $protocol;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $catalog->upsert([
            'category_id' => $categoryIds['game'],
            'name' => 'Game port ' . $range . '/' . $protocol,
            'entry_type' => 'PORT',
            'value' => $range,
            'port' => $start,
            'protocol' => $protocol,
            'source_type' => 'IMPORT',
            'confidence' => 100,
            'description' => $description . ' (official vendor port documentation).',
            'metadata' => [
                'seed_revision' => '2026-09-26',
                'source_urls' => [$sourceUrl],
                'method' => 'Vendor-published port/range; TCP and UDP kept as separate entries.',
            ],
        ]);
        $inserted++;
    }

    if (isset($options['dry-run'])) {
        $db->rollBack();
        echo "Dry run passed; database changes rolled back.\n";
    } else {
        $db->commit();
        echo "Catalog reset and reseed completed.\n";
    }
    echo "Seed rows: {$inserted}\n";
    echo "Categories available: " . count($categoryIds) . "\n";
    echo "Backup used: {$backupPath}\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Catalog reset rolled back: ' . $e->getMessage() . "\n");
    exit(1);
}
