<?php
// ==========================================
// KONFIGURASI GLOBAL
// ==========================================
// PENTING: API Key sekarang WAJIB diset lewat Environment Variable di Vercel.
// Cara set: Vercel Dashboard -> Project ytcheker -> Settings -> Environment Variables
// Key   : YOUTUBE_API_KEY
// Value : (API key baru hasil rotate dari Google Cloud Console)
// Lalu redeploy project.
$GLOBAL_API_KEY = getenv('YOUTUBE_API_KEY');

// Fungsi Helper untuk melakukan HTTP Request (Lebih stabil di Vercel dibanding file_get_contents)
function fetch_url_curl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Mencegah error SSL di Vercel
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

// Helper: format pesan error API YouTube jadi lebih ramah dibaca user
function friendly_api_error($reason) {
    $map = [
        'quotaExceeded'   => 'Kuota API YouTube harian sudah habis. Data statistik mungkin tidak lengkap, sistem mencoba metode cadangan.',
        'commentsDisabled'=> 'Video ini menonaktifkan kolom komentar.',
        'videoNotFound'   => 'Video tidak ditemukan atau sudah dihapus/private.',
        'forbidden'       => 'Akses API ditolak untuk video ini.',
    ];
    return $map[$reason] ?? 'Terjadi kendala saat mengambil data dari API YouTube.';
}

// ==========================================
// KERNEL PHP (BACKEND) - HYBRID API & SPOOFING
// ==========================================
if (isset($_GET['action']) && $_GET['action'] == 'fetch_metadata') {

    error_reporting(0);
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);
    $url = $input['url'] ?? '';

    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL) || strpos($url, 'youtu') === false) {
        echo json_encode(['error' => 'URL YouTube tidak valid.']);
        exit;
    }

    // Ekstrak Video ID (Support Shorts & URL Standar)
    $videoId = '';
    if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?|shorts)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $url, $match)) {
        $videoId = $match[1];
    }

    $thumbnail = $videoId ? "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg" : "";

    // Default Values
    $title = 'Tidak diketahui';
    $uploadDate = null;
    $datePublished = null;
    $genre = 'Tidak diketahui';
    $views = null;
    $likes = null;
    $comments = null;
    $description = 'Tidak ada deskripsi.';
    $apiWarning = null;

    // Data Channel (baru)
    $channelId = '';
    $channelTitle = 'Tidak diketahui';
    $channelThumbnail = '';
    $subscriberCount = null;
    $subscriberHidden = false;
    $channelVideoCount = null;

    // ==========================================================
    // 1. AMBIL STATISTIK & METADATA VIA YOUTUBE DATA API V3 (Full)
    // ==========================================================
    $apiSuccess = false;

    // Daftar Kategori YouTube API (Mapping dari ID ke Teks)
    $youtubeCategories = [
        "1" => "Film & Animation", "2" => "Autos & Vehicles", "10" => "Music",
        "15" => "Pets & Animals", "17" => "Sports", "18" => "Short Movies",
        "19" => "Travel & Events", "20" => "Gaming", "21" => "Videoblogging",
        "22" => "People & Blogs", "23" => "Comedy", "24" => "Entertainment",
        "25" => "News & Politics", "26" => "How-to & Style", "27" => "Education",
        "28" => "Science & Technology", "29" => "Nonprofits & Activism"
    ];

    if (!empty($videoId) && !empty($GLOBAL_API_KEY)) {
        $apiUrl = "https://www.googleapis.com/youtube/v3/videos?part=snippet,statistics&id={$videoId}&key={$GLOBAL_API_KEY}";

        $apiResponse = fetch_url_curl($apiUrl);
        if ($apiResponse) {
            $apiData = json_decode($apiResponse, true);

            if (isset($apiData['error'])) {
                $reason = $apiData['error']['errors'][0]['reason'] ?? '';
                $apiWarning = friendly_api_error($reason);
            } elseif (!empty($apiData['items'])) {
                $item = $apiData['items'][0];

                // Ambil Data Statistik
                $stats = $item['statistics'];
                $views    = $stats['viewCount'] ?? null;
                $likes    = $stats['likeCount'] ?? null;
                $comments = $stats['commentCount'] ?? null;

                // Ambil Data Metadata (Snippet)
                $snippet = $item['snippet'];
                $title = $snippet['title'] ?? 'Tidak diketahui';
                $description = $snippet['description'] ?? 'Tidak ada deskripsi.';

                // Format Waktu Upload (ISO 8601)
                $uploadDate = $snippet['publishedAt'] ?? null;
                $datePublished = $uploadDate;

                // Konversi Kategori ID menjadi Teks
                $categoryId = $snippet['categoryId'] ?? '';
                $genre = $youtubeCategories[$categoryId] ?? 'Tidak diketahui';

                // Gunakan Resolusi Thumbnail Maksimal jika tersedia
                if (isset($snippet['thumbnails']['maxres']['url'])) {
                    $thumbnail = $snippet['thumbnails']['maxres']['url'];
                } elseif (isset($snippet['thumbnails']['high']['url'])) {
                    $thumbnail = $snippet['thumbnails']['high']['url'];
                }

                // --- BARU: Data Channel ---
                $channelId = $snippet['channelId'] ?? '';
                $channelTitle = $snippet['channelTitle'] ?? 'Tidak diketahui';

                if (!empty($channelId)) {
                    $chUrl = "https://www.googleapis.com/youtube/v3/channels?part=snippet,statistics&id={$channelId}&key={$GLOBAL_API_KEY}";
                    $chResponse = fetch_url_curl($chUrl);
                    if ($chResponse) {
                        $chData = json_decode($chResponse, true);
                        if (!empty($chData['items'])) {
                            $chItem = $chData['items'][0];
                            $channelThumbnail = $chItem['snippet']['thumbnails']['default']['url'] ?? '';
                            $chStats = $chItem['statistics'] ?? [];
                            $subscriberHidden = (bool)($chStats['hiddenSubscriberCount'] ?? false);
                            $subscriberCount = $subscriberHidden ? null : ($chStats['subscriberCount'] ?? null);
                            $channelVideoCount = $chStats['videoCount'] ?? null;
                        }
                    }
                }

                $apiSuccess = true;
            }
        }
    } elseif (empty($GLOBAL_API_KEY)) {
        $apiWarning = 'YOUTUBE_API_KEY belum diset di Environment Variables Vercel. Sistem berjalan mode cadangan (scraping) saja.';
    }

    // ==========================================================
    // 2. AMBIL METADATA LAINNYA VIA SCRAPING (Googlebot Spoofing)
    // ==========================================================
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7",
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"
    ]);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($html === false) {
        $html = '';
    }

    if ($html === '' && !$apiSuccess) {
        echo json_encode(['error' => 'Gagal mengambil data. Server YouTube menolak permintaan dari Vercel/Datacenter.']);
        exit;
    }

    // --- EKSTRAKSI METADATA DASAR ---
    if (preg_match('/itemprop="uploadDate"\s+content="([^"]+)"/i', $html, $m)) { $uploadDate = $m[1]; }
    elseif (preg_match('/"uploadDate":"([^"]+)"/i', $html, $m)) { $uploadDate = $m[1]; }

    if (preg_match('/itemprop="datePublished"\s+content="([^"]+)"/i', $html, $m)) { $datePublished = $m[1]; }
    elseif (preg_match('/"publishDate":"([^"]+)"/i', $html, $m)) { $datePublished = $m[1]; }

    if (preg_match('/itemprop="genre"\s+content="([^"]+)"/i', $html, $m)) { $genre = $m[1]; }
    elseif (preg_match('/"category":"([^"]+)"/i', $html, $m)) { $genre = $m[1]; }

    if (preg_match('/<title>(.*?)<\/title>/i', $html, $m)) { $title = str_replace(' - YouTube', '', $m[1]); }
    elseif (preg_match('/"title":\{"simpleText":"([^"]+)"\}/i', $html, $m)) { $title = $m[1]; }

    if (empty($thumbnail) && preg_match('/<meta property="og:image" content="([^"]+)">/i', $html, $m)) {
        $thumbnail = $m[1];
    }

    // --- EKSTRAKSI DESKRIPSI ---
    if (preg_match('/"(?:shortD|d)escription":"(.*?)"(?=,"(?:title|isCrawlable)")/i', $html, $m)) {
        $descRaw = $m[1];
        $descRaw = str_replace(['\n', '\"', '\/'], ["\n", '"', '/'], $descRaw);
        $decoded = json_decode('"' . $descRaw . '"');
        $description = $decoded ?: $descRaw;
    } elseif (preg_match('/<meta itemprop="description" content="([^"]+)">/i', $html, $m)) {
        $description = $m[1];
    } elseif (preg_match('/<meta property="og:description" content="([^"]+)">/i', $html, $m)) {
        $description = $m[1];
    }

    // --- BARU: Fallback nama channel dari scraping (kalau API gagal total) ---
    if ($channelTitle === 'Tidak diketahui' && preg_match('/"author":"([^"]+)"/i', $html, $m)) {
        $channelTitle = $m[1];
    }

    // ==========================================================
    // 3. FALLBACK REGEX (Hanya jika API gagal)
    // ==========================================================
    if (!$apiSuccess && $html !== '') {
        if (preg_match('/itemprop="interactionCount"\s+content="(\d+)"/i', $html, $m)) {
            $views = $m[1];
        } elseif (preg_match('/"viewCount":"(\d+)"/i', $html, $m)) {
            $views = $m[1];
        }

        if (preg_match('/"defaultText":\{"accessibility":\{"accessibilityData":\{"label":"([0-9,.\s]+)likes?"/i', $html, $m)) {
            $likes = preg_replace('/[^0-9]/', '', $m[1]);
        } elseif (preg_match('/"likeCount":"(\d+)"/i', $html, $m)) {
            $likes = $m[1];
        }

        if (preg_match('/"commentCount":\{"simpleText":"([0-9,.]+)"\}/i', $html, $m)) {
            $comments = preg_replace('/[^0-9]/', '', $m[1]);
        }
    }

    // ==========================================================
    // 4. KIRIM HASIL KE FRONT-END
    // ==========================================================
    echo json_encode([
        'success'           => true,
        'videoId'           => $videoId,
        'title'             => html_entity_decode($title),
        'uploadDate'        => $uploadDate,
        'datePublished'     => $datePublished,
        'genre'             => html_entity_decode($genre),
        'thumbnail'         => $thumbnail,
        'views'             => $views,
        'likes'             => $likes,
        'comments'          => $comments,
        'description'       => html_entity_decode($description),
        'apiWarning'        => $apiWarning,
        // Data channel baru
        'channelId'         => $channelId,
        'channelTitle'      => html_entity_decode($channelTitle),
        'channelThumbnail'  => $channelThumbnail,
        'subscriberCount'   => $subscriberCount,
        'subscriberHidden'  => $subscriberHidden,
        'channelVideoCount' => $channelVideoCount
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// KERNEL PHP (BACKEND) - GET RANDOM COMMENT (dengan dukungan mini settings)
// ==========================================
if (isset($_GET['action']) && $_GET['action'] == 'get_random_comment') {
    error_reporting(0);
    ini_set('display_errors', 0);
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);
    $videoId = $input['videoId'] ?? '';

    if (empty($videoId) || empty($GLOBAL_API_KEY)) {
        echo json_encode(['error' => 'Video ID atau API Key tidak valid.']);
        exit;
    }

    // Ambil hingga 50 komentar (maksimal yang wajar per request) supaya
    // filter kata kunci / dedupe di mini settings punya cukup data untuk dipilih.
    $url = "https://www.googleapis.com/youtube/v3/commentThreads?part=snippet&videoId={$videoId}&maxResults=50&order=relevance&key={$GLOBAL_API_KEY}";

    $response = fetch_url_curl($url);

    if ($response) {
        $data = json_decode($response, true);

        if (isset($data['error'])) {
            $reason = $data['error']['errors'][0]['reason'] ?? '';
            echo json_encode(['error' => friendly_api_error($reason)]);
            exit;
        }

        if (!empty($data['items'])) {
            $commentsList = [];
            foreach ($data['items'] as $item) {
                $c = $item['snippet']['topLevelComment']['snippet'];
                $commentsList[] = [
                    'author' => $c['authorDisplayName'],
                    'text'   => $c['textOriginal'],
                    'avatar' => $c['authorProfileImageUrl']
                ];
            }

            // Pemilihan pemenang tunggal tetap dikirim untuk kompatibilitas lama,
            // tapi pemilihan sesuai mini settings (jumlah, keyword, dedupe)
            // sekarang dilakukan di sisi frontend dari commentsList ini.
            $randomKey = array_rand($commentsList);
            echo json_encode([
                'success'      => true,
                'commentsList' => $commentsList,
                'author'       => $commentsList[$randomKey]['author'],
                'text'         => $commentsList[$randomKey]['text'],
                'avatar'       => $commentsList[$randomKey]['avatar']
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo json_encode(['error' => 'Video ini tidak memiliki komentar.']);
    exit;
}

?>

<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YouTube Metadata Checker Pro</title>
    <meta name="description" content="Cek metadata video YouTube secara instan: judul, views, likes, comments, deskripsi, genre, tanggal upload, info channel, dan random comment picker untuk giveaway.">
    <meta property="og:title" content="YouTube Metadata Checker Pro">
    <meta property="og:description" content="Cek metadata lengkap video YouTube dan pilih pemenang komentar acak untuk giveaway.">
    <meta property="og:type" content="website">
    <meta name="theme-color" content="#ff0000">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>▶️</text></svg>">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-body: #f4f7f6;
            --bg-card: #ffffff;
            --bg-input: #ffffff;
            --text-main: #222222;
            --text-muted: #777777;
            --border-color: #e2e8f0;
            --accent: #ff0000;
            --accent-hover: #cc0000;
            --gold: #d97706;
            --shadow: 0 10px 30px rgba(0,0,0,0.08);
            --item-bg: #f8fafc;
            --btn-paste-bg: #f1f5f9;
            --btn-paste-hover: #e2e8f0;
        }

        [data-theme="dark"] {
            --bg-body: #0f172a;
            --bg-card: #1e293b;
            --bg-input: #0f172a;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border-color: #334155;
            --accent: #ef4444;
            --accent-hover: #dc2626;
            --gold: #f5b942;
            --shadow: 0 10px 30px rgba(0,0,0,0.4);
            --item-bg: #0f172a;
            --btn-paste-bg: #1e293b;
            --btn-paste-hover: #334155;
        }

        * { box-sizing: border-box; font-family: 'Poppins', sans-serif; margin: 0; padding: 0; transition: background 0.3s, color 0.3s; }

        body { background: var(--bg-body); color: var(--text-main); padding: 40px 20px; display: flex; justify-content: center; min-height: 100vh; }

        .container { background: var(--bg-card); width: 100%; max-width: 650px; padding: 35px; border-radius: 20px; box-shadow: var(--shadow); border: 1px solid var(--border-color); position: relative; height: fit-content;}

        .header-wrap { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        h2 { font-size: 24px; color: var(--text-main); font-weight: 700; }
        h2 span { color: var(--accent); }

        .theme-toggle { background: none; border: none; font-size: 24px; cursor: pointer; outline: none; padding: 5px; }

        .input-wrapper { position: relative; width: 100%; margin-bottom: 10px; }

        input[type="url"] {
            width: 100%;
            padding: 16px 110px 16px 20px;
            background: var(--bg-input);
            color: var(--text-main);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            font-size: 15px;
            outline: none;
            transition: 0.3s;
        }
        input[type="url"]:focus { border-color: var(--accent); box-shadow: 0 0 0 4px rgba(255,0,0,0.1); }

        .paste-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: var(--btn-paste-bg);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .paste-btn:hover { background: var(--btn-paste-hover); }

        /* BARU: Riwayat Pencarian */
        .history-row {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 4px;
            margin-bottom: 16px;
        }
        .history-row::-webkit-scrollbar { height: 4px; }
        .history-chip {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            gap: 6px;
            background: var(--item-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 5px 12px 5px 5px;
            font-size: 12px;
            color: var(--text-muted);
            cursor: pointer;
            transition: 0.2s;
            max-width: 180px;
        }
        .history-chip:hover { border-color: var(--accent); color: var(--text-main); }
        .history-chip img { width: 20px; height: 20px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
        .history-chip span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        button.primary-btn { width: 100%; padding: 16px; background: var(--accent); color: #fff; border: none; border-radius: 12px; font-size: 16px; font-weight: 600; cursor: pointer; transition: transform 0.2s, background 0.3s; }
        button.primary-btn:hover { background: var(--accent-hover); transform: translateY(-2px); }
        button.primary-btn:disabled { background: #94a3b8; cursor: not-allowed; transform: none; }

        .lang-switcher {
            display: none;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            animation: fadeIn 0.5s ease;
        }
        .lang-label { font-size: 13px; font-weight: 600; color: var(--text-muted); }
        .flag-btn {
            background: none; border: 2px solid transparent; border-radius: 50%; font-size: 26px; cursor: pointer; opacity: 0.4; transition: 0.3s; padding: 2px; filter: grayscale(80%);
        }
        .flag-btn.active { opacity: 1; filter: grayscale(0%); transform: scale(1.15); border-color: var(--accent); }
        .flag-btn:hover { opacity: 0.8; filter: grayscale(20%); }

        .loader { display: none; text-align: center; margin-top: 20px; font-size: 14px; color: var(--text-muted); font-weight: 500;}
        .error-msg { display: none; margin-top: 20px; padding: 15px; background: #fee2e2; color: #b91c1c; border-radius: 10px; font-size: 14px; text-align: center; font-weight: 500;}
        .warning-msg { display: none; margin-top: 20px; padding: 12px 15px; background: #fef3c7; color: #92400e; border-radius: 10px; font-size: 13px; text-align: center; font-weight: 500;}
        [data-theme="dark"] .warning-msg { background: #451a03; color: #fbbf24; }

        .result-card { display: none; margin-top: 20px; animation: fadeIn 0.5s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .thumbnail-container { width: 100%; border-radius: 16px; overflow: hidden; margin-bottom: 20px; border: 1px solid var(--border-color); aspect-ratio: 16 / 9; background: var(--item-bg); display: flex; align-items: center; justify-content: center; }
        .thumbnail-container img { width: 100%; height: 100%; object-fit: cover; }

        /* BARU: Kartu Info Channel */
        .channel-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            background: var(--item-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .channel-card img { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: var(--border-color); }
        .channel-info { flex: 1; min-width: 0; }
        .channel-name { font-size: 14px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .channel-stats { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

        .details-grid { display: flex; flex-direction: column; gap: 12px; }

        .stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }

        .result-item { padding: 16px; background: var(--item-bg); border: 1px solid var(--border-color); border-radius: 12px; display: flex; flex-direction: column; }
        .result-item.stat-box { align-items: center; text-align: center; padding: 12px; }

        .label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.8px; margin-bottom: 6px; }

        /* BARU: baris label sejajar dengan tombol copy kecil (mis. Deskripsi Video) */
        .label-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
        .label-row .label { margin-bottom: 0; }
        .mini-copy-btn {
            font-size: 10px;
            padding: 4px 9px;
            background: var(--btn-paste-bg);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: 0.2s;
            flex-shrink: 0;
        }
        .mini-copy-btn:hover { background: var(--btn-paste-hover); }
        .mini-copy-btn.success { background: #10b981; color: #fff; border-color: #10b981; }
        .value { font-size: 15px; font-weight: 500; color: var(--text-main); word-break: break-word; line-height: 1.5; }
        .value.highlight { color: var(--accent); font-weight: 600; font-size: 16px;}

        .desc-box {
            max-height: 150px;
            overflow-y: auto;
            background: var(--bg-input);
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            font-size: 13px;
            white-space: pre-wrap;
            line-height: 1.6;
        }
        .desc-box::-webkit-scrollbar { width: 6px; }
        .desc-box::-webkit-scrollbar-track { background: transparent; }
        .desc-box::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 10px; }

        .copy-btn { margin-top: 20px; width: 100%; padding: 14px; background: transparent; color: var(--text-main); border: 2px solid var(--border-color); border-radius: 12px; font-size: 15px; font-weight: 600; cursor: pointer; transition: 0.3s; display: flex; justify-content: center; align-items: center; gap: 8px; }
        .copy-btn:hover { background: var(--item-bg); border-color: var(--text-muted); }
        .copy-btn.success { background: #10b981; color: white; border-color: #10b981; }

        @media(max-width: 600px) {
            .container { padding: 25px 20px; }
            h2 { font-size: 20px; }
            .stats-row { grid-template-columns: 1fr; }
        }

        /* Baris tombol random + settings */
        .random-row { margin-top: 10px; display: flex; gap: 6px; justify-content: center; align-items: center; }
        .random-btn {
            font-size: 11px;
            padding: 6px 12px;
            background: #fee2e2;
            color: #dc2626;
            border: none;
            border-radius: 20px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .random-btn:hover { background: #fca5a5; transform: scale(1.05); }
        .random-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        [data-theme="dark"] .random-btn { background: #7f1d1d; color: #fca5a5; }
        [data-theme="dark"] .random-btn:hover { background: #991b1b; }

        .gear-btn {
            font-size: 13px;
            width: 26px; height: 26px;
            border-radius: 50%;
            border: 1px solid var(--border-color);
            background: var(--item-bg);
            color: var(--text-main);
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
        }
        .gear-btn:hover { border-color: var(--accent); }

        /* BARU: Mini Settings Panel untuk Random Winner */
        .settings-panel {
            display: none;
            margin-top: 12px;
            padding: 14px;
            background: var(--item-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            text-align: left;
        }
        .settings-panel.open { display: block; }
        .settings-panel .s-title { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); font-weight: 700; margin-bottom: 12px; }
        .s-field { margin-bottom: 12px; }
        .s-field:last-child { margin-bottom: 0; }
        .s-label-row { display: flex; justify-content: space-between; align-items: center; font-size: 13px; margin-bottom: 6px; }
        .s-label-row .s-val { color: var(--gold); font-weight: 700; }
        .s-field input[type="range"] { width: 100%; accent-color: var(--accent); }
        .s-field input[type="text"] {
            width: 100%; padding: 8px 10px; border-radius: 8px;
            border: 1px solid var(--border-color); background: var(--bg-input); color: var(--text-main); font-size: 13px; outline: none;
        }
        .s-toggle-row { display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
        .s-switch { width: 38px; height: 22px; border-radius: 99px; background: var(--border-color); position: relative; cursor: pointer; flex-shrink: 0; }
        .s-switch.on { background: var(--accent); }
        .s-switch::after { content: ""; position: absolute; top: 2px; left: 2px; width: 18px; height: 18px; border-radius: 50%; background: #fff; transition: left .15s ease; }
        .s-switch.on::after { left: 18px; }

        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.7); display: none; align-items: center; justify-content: center;
            z-index: 9999; backdrop-filter: blur(5px);
        }
        .modal-content {
            background: var(--bg-card); width: 90%; max-width: 380px;
            padding: 30px 20px; border-radius: 24px; text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 2px solid var(--border-color);
            max-height: 85vh; overflow-y: auto;
        }
        .modal-avatar {
            width: 90px; height: 90px; border-radius: 50%; margin: 0 auto 15px;
            object-fit: cover; border: 4px solid var(--accent); box-shadow: 0 4px 10px rgba(255,0,0,0.2);
        }
        .modal-author { font-size: 18px; font-weight: 800; color: var(--text-main); margin-bottom: 8px; }
        .modal-text {
            font-size: 14px; color: var(--text-muted); line-height: 1.6; margin-bottom: 25px;
            max-height: 150px; overflow-y: auto; background: var(--item-bg); padding: 15px; border-radius: 12px;
        }

        /* BARU: daftar pemenang (jika jumlah pemenang > 1) */
        .winner-list { text-align: left; margin-bottom: 20px; }
        .winner-card {
            display: flex; align-items: center; gap: 10px;
            background: var(--item-bg); border: 1px solid var(--border-color);
            border-radius: 12px; padding: 10px; margin-bottom: 8px;
        }
        .winner-rank {
            width: 24px; height: 24px; border-radius: 50%;
            background: rgba(217,119,6,0.15); color: var(--gold);
            font-size: 11px; font-weight: 800;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .winner-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: var(--border-color); }
        .winner-info { min-width: 0; flex: 1; }
        .winner-name { font-size: 13px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .winner-text { font-size: 11px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .close-modal-btn {
            background: var(--bg-body); color: var(--text-main); border: 1px solid var(--border-color);
            padding: 10px 24px; border-radius: 12px; cursor: pointer; font-weight: 600; transition: 0.3s;
        }
        .close-modal-btn:hover { background: var(--border-color); }
        .close-modal-btn:disabled { opacity: 0.5; cursor: not-allowed; }

    </style>
</head>
<body>

<div class="container">
    <div class="header-wrap">
        <h2>YT <span>Metadata</span> Checker</h2>
        <button id="themeToggle" class="theme-toggle">🌙</button>
    </div>

    <div class="input-wrapper">
        <input type="url" id="ytUrl" placeholder="Contoh: https://youtu.be/dQw4w9WgXcQ" autocomplete="off" required>
        <button id="pasteBtn" class="paste-btn">📋 Paste</button>
    </div>

    <!-- BARU: Riwayat Pencarian -->
    <div class="history-row" id="historyRow"></div>

    <button id="checkBtn" class="primary-btn">Cek Metadata</button>

    <div id="langSwitcher" class="lang-switcher">
        <span class="lang-label" id="lblFormat">Format:</span>
        <button class="flag-btn active" data-lang="id-ID" title="Bahasa Indonesia">🇮🇩</button>
        <button class="flag-btn" data-lang="en-US" title="English">🇬🇧</button>
    </div>

    <div class="loader" id="loader">Menganalisis data dari YouTube...</div>
    <div class="error-msg" id="errorMsg"></div>
    <div class="warning-msg" id="warningMsg"></div>

    <div class="result-card" id="resultCard">
        <div class="thumbnail-container">
            <img id="resThumbnail" src="" alt="Thumbnail YouTube">
        </div>

        <!-- BARU: Kartu Info Channel -->
        <div class="channel-card" id="channelCard" style="display:none;">
            <img id="chAvatar" src="" alt="">
            <div class="channel-info">
                <div class="channel-name" id="chName">-</div>
                <div class="channel-stats" id="chStats">-</div>
            </div>
        </div>

        <div class="details-grid">
            <div class="result-item">
                <span class="label" id="lblTitle">Judul Video</span>
                <span class="value" id="resTitle">-</span>
            </div>

            <div class="stats-row">
                <div class="result-item stat-box">
                    <span class="label" id="lblViews">👁️ Views</span>
                    <span class="value highlight" id="resViews">-</span>
                </div>
                <div class="result-item stat-box">
                    <span class="label" id="lblLikes">👍 Likes</span>
                    <span class="value highlight" id="resLikes">-</span>
                </div>
                <div class="result-item stat-box">
                    <span class="label" id="lblComments">💬 Comments</span>
                    <span class="value highlight" id="resComments">-</span>
                    <div class="random-row" id="randomRow" style="display:none;">
                        <button id="btnRandomComment" class="random-btn">🎲 Get Random</button>
                        <button id="btnSettingsGear" class="gear-btn" title="Pengaturan">⚙️</button>
                    </div>
                    <div class="settings-panel" id="settingsPanel">
                        <div class="s-title">Mini Settings</div>
                        <div class="s-field">
                            <div class="s-label-row"><span>Jumlah Pemenang</span><span class="s-val" id="countVal">1</span></div>
                            <input type="range" id="countRange" min="1" max="10" value="1">
                        </div>
                        <div class="s-field">
                            <div class="s-toggle-row">
                                <span>Hindari nama ganda</span>
                                <div class="s-switch on" id="toggleDedupe"></div>
                            </div>
                        </div>
                        <div class="s-field">
                            <input type="text" id="keywordInput" placeholder="Kata kunci wajib (opsional)">
                        </div>
                    </div>
                </div>
            </div>

            <div class="result-item">
                <span class="label" id="lblGenre">Genre / Kategori</span>
                <span class="value" id="resGenre">-</span>
            </div>
            <div class="result-item">
                <div class="label-row">
                    <span class="label" id="lblDesc">Deskripsi Video</span>
                    <button id="copyDescBtn" class="mini-copy-btn" type="button">📋 <span id="lblCopyDesc">Salin</span></button>
                </div>
                <div class="desc-box" id="resDesc">-</div>
            </div>
            <div class="result-item">
                <span class="label" id="lblRaw">Upload Date (Raw Source)</span>
                <span class="value" id="resRawUpload" style="font-family: monospace;">-</span>
            </div>
            <div class="result-item">
                <span class="label" id="lblLocal">Waktu Upload (Lokal Anda)</span>
                <span class="value highlight" id="resLocalUpload">-</span>
            </div>
        </div>

        <button id="copyBtn" class="copy-btn">📋 <span id="lblCopy">Salin Semua Detail</span></button>
    </div>
</div>

<div id="randomModal" class="modal-overlay">
    <div class="modal-content">
        <div id="singleWinnerView">
            <img id="animAvatar" src="https://ui-avatars.com/api/?name=?&background=random" class="modal-avatar" alt="Avatar">
            <h4 id="animAuthor" class="modal-author">Mengacak...</h4>
            <div id="animText" class="modal-text">Mencari pemenang yang beruntung... ⏳</div>
        </div>
        <div id="multiWinnerView" class="winner-list" style="display:none;"></div>
        <button id="btnCloseModal" class="close-modal-btn" onclick="closeRandomModal()" disabled>Tutup</button>
    </div>
</div>

<script>
let globalVideoId = "";

function extractVideoId(url) {
    const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=|shorts\/)([^#\&\?]*).*/;
    const match = url.match(regExp);
    return (match && match[2].length === 11) ? match[2] : null;
}

// ==========================================================
// BARU: RIWAYAT PENCARIAN (localStorage)
// ==========================================================
const HISTORY_KEY = 'yt_checker_history';

function getHistory() {
    try { return JSON.parse(localStorage.getItem(HISTORY_KEY)) || []; }
    catch (e) { return []; }
}

function saveHistory(entry) {
    let history = getHistory().filter(h => h.videoId !== entry.videoId);
    history.unshift(entry);
    history = history.slice(0, 8);
    localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
    renderHistory();
}

function renderHistory() {
    const row = document.getElementById('historyRow');
    const history = getHistory();
    if (history.length === 0) { row.style.display = 'none'; return; }
    row.style.display = 'flex';
    row.innerHTML = '';
    history.forEach(h => {
        const chip = document.createElement('div');
        chip.className = 'history-chip';
        chip.innerHTML = `<img src="${h.thumbnail}" alt=""><span>${h.title}</span>`;
        chip.addEventListener('click', () => {
            document.getElementById('ytUrl').value = `https://youtu.be/${h.videoId}`;
            document.getElementById('checkBtn').click();
        });
        row.appendChild(chip);
    });
}
renderHistory();

// ==========================================================
// GET RANDOM WINNER + MINI SETTINGS
// ==========================================================
const btnRandom = document.getElementById('btnRandomComment');
const btnGear = document.getElementById('btnSettingsGear');
const settingsPanel = document.getElementById('settingsPanel');
const randomModal = document.getElementById('randomModal');
const animAvatar = document.getElementById('animAvatar');
const animAuthor = document.getElementById('animAuthor');
const animText = document.getElementById('animText');
const btnCloseModal = document.getElementById('btnCloseModal');
const singleWinnerView = document.getElementById('singleWinnerView');
const multiWinnerView = document.getElementById('multiWinnerView');

const countRange = document.getElementById('countRange');
const countVal = document.getElementById('countVal');
const toggleDedupe = document.getElementById('toggleDedupe');
const keywordInput = document.getElementById('keywordInput');

let randomSettings = { count: 1, dedupe: true, keyword: '' };

btnGear.addEventListener('click', () => settingsPanel.classList.toggle('open'));
countRange.addEventListener('input', () => {
    randomSettings.count = parseInt(countRange.value, 10);
    countVal.textContent = randomSettings.count;
});
toggleDedupe.addEventListener('click', () => {
    toggleDedupe.classList.toggle('on');
    randomSettings.dedupe = toggleDedupe.classList.contains('on');
});
keywordInput.addEventListener('input', (e) => {
    randomSettings.keyword = e.target.value.trim().toLowerCase();
});

let spinInterval;
let liveComments = [];
let commentsFetchedForVideo = null;

async function prefetchComments(videoId) {
    if (commentsFetchedForVideo === videoId) return;
    try {
        const res = await fetch('?action=get_random_comment', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ videoId: videoId })
        });
        const data = await res.json();
        if (data.success && data.commentsList) {
            liveComments = data.commentsList;
            commentsFetchedForVideo = videoId;
        }
    } catch (e) {
        console.log("Gagal mengambil komentar.");
    }
}

function shuffleArr(arr) {
    const a = [...arr];
    for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
}

function pickWinners(comments, settings) {
    let pool = comments;
    if (settings.keyword) {
        pool = pool.filter(c => c.text.toLowerCase().includes(settings.keyword));
    }
    pool = shuffleArr(pool);

    const winners = [];
    const seenAuthors = new Set();
    for (const c of pool) {
        if (winners.length >= settings.count) break;
        if (settings.dedupe && seenAuthors.has(c.author)) continue;
        winners.push(c);
        seenAuthors.add(c.author);
    }
    return winners;
}

btnRandom.addEventListener('click', async () => {
    const url = document.getElementById('ytUrl').value;
    globalVideoId = extractVideoId(url);

    if (!globalVideoId) {
        alert("Video ID tidak ditemukan.");
        return;
    }

    randomModal.style.display = 'flex';
    btnCloseModal.disabled = true;
    singleWinnerView.style.display = 'block';
    multiWinnerView.style.display = 'none';
    multiWinnerView.innerHTML = '';

    spinInterval = setInterval(() => {
        if (liveComments && liveComments.length > 0) {
            const randIdx = Math.floor(Math.random() * liveComments.length);
            const item = liveComments[randIdx];
            animAuthor.innerText = item.author;
            animText.innerText = item.text.substring(0, 50) + "...";
            animAvatar.src = item.avatar;
        } else {
            animAuthor.innerText = "Mengacak...";
            animText.innerText = "Memuat data dari YouTube...";
        }
    }, 100);

    await prefetchComments(globalVideoId);

    setTimeout(() => {
        clearInterval(spinInterval);
        btnCloseModal.disabled = false;

        if (!liveComments || liveComments.length === 0) {
            animAuthor.innerText = "❌ Gagal";
            animText.innerText = "Video ini tidak memiliki komentar, atau gagal memuat.";
            return;
        }

        const winners = pickWinners(liveComments, randomSettings);

        if (winners.length === 0) {
            animAuthor.innerText = "❌ Tidak ditemukan";
            animText.innerText = "Tidak ada komentar yang cocok dengan kata kunci. Coba longgarkan filter.";
            return;
        }

        if (winners.length === 1) {
            singleWinnerView.style.display = 'block';
            multiWinnerView.style.display = 'none';
            animAuthor.innerText = "🏆 " + winners[0].author;
            animText.innerHTML = `<strong>Komentar:</strong><br><br>"${winners[0].text}"`;
            animAvatar.src = winners[0].avatar;
        } else {
            singleWinnerView.style.display = 'none';
            multiWinnerView.style.display = 'block';
            multiWinnerView.innerHTML = winners.map((w, i) => `
                <div class="winner-card">
                    <div class="winner-rank">${i + 1}</div>
                    <img class="winner-avatar" src="${w.avatar}" alt="">
                    <div class="winner-info">
                        <div class="winner-name">${w.author}</div>
                        <div class="winner-text">${w.text}</div>
                    </div>
                </div>
            `).join('');
        }
    }, 3000);
});

function closeRandomModal() {
    randomModal.style.display = 'none';
}

const themeToggle = document.getElementById('themeToggle');
const htmlEl = document.documentElement;
const savedTheme = localStorage.getItem('yt-theme') || 'light';
setTheme(savedTheme);

themeToggle.addEventListener('click', () => {
    const currentTheme = htmlEl.getAttribute('data-theme');
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    setTheme(newTheme);
});

function setTheme(theme) {
    htmlEl.setAttribute('data-theme', theme);
    localStorage.setItem('yt-theme', theme);
    themeToggle.innerText = theme === 'light' ? '🌙' : '☀️';
}

document.getElementById('pasteBtn').addEventListener('click', async () => {
    try {
        const text = await navigator.clipboard.readText();
        document.getElementById('ytUrl').value = text;
    } catch (err) {
        alert('Browser Anda belum memberikan izin Paste otomatis. Silakan tahan dan tempel manual (Ctrl+V).');
    }
});

let currentLang = 'id-ID';
let globalRawDate = null;
let rawStats = { views: null, likes: null, comments: null };

const textDict = {
    'id-ID': {
        format: 'Bahasa Format:', title: 'Judul Video', genre: 'Genre / Kategori',
        desc: 'Deskripsi Video', views: '👁️ Views', likes: '👍 Likes', comments: '💬 Comments',
        raw: 'Upload Date (Raw Source)', local: 'Waktu Upload (Lokal Anda)',
        copy: 'Salin Semua Detail', unknown: 'Tidak diketahui', subsHidden: 'Disembunyikan',
        subscribers: 'subscriber', videos: 'video', copyDesc: 'Salin', copiedDesc: 'Tersalin!'
    },
    'en-US': {
        format: 'Language Format:', title: 'Video Title', genre: 'Genre / Category',
        desc: 'Video Description', views: '👁️ Views', likes: '👍 Likes', comments: '💬 Comments',
        raw: 'Upload Date (Raw Source)', local: 'Upload Time (Your Local Time)',
        copy: 'Copy All Details', unknown: 'Unknown', subsHidden: 'Hidden',
        subscribers: 'subscribers', videos: 'videos', copyDesc: 'Copy', copiedDesc: 'Copied!'
    }
};

document.querySelectorAll('.flag-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
        document.querySelectorAll('.flag-btn').forEach(b => b.classList.remove('active'));
        e.currentTarget.classList.add('active');
        currentLang = e.currentTarget.getAttribute('data-lang');
        applyLanguage(currentLang);
        if (globalRawDate) renderDate(globalRawDate);
        renderStats();
    });
});

function applyLanguage(lang) {
    document.getElementById('lblFormat').innerText = textDict[lang].format;
    document.getElementById('lblTitle').innerText = textDict[lang].title;
    document.getElementById('lblGenre').innerText = textDict[lang].genre;
    document.getElementById('lblDesc').innerText = textDict[lang].desc;
    document.getElementById('lblViews').innerText = textDict[lang].views;
    document.getElementById('lblLikes').innerText = textDict[lang].likes;
    document.getElementById('lblComments').innerText = textDict[lang].comments;
    document.getElementById('lblRaw').innerText = textDict[lang].raw;
    document.getElementById('lblLocal').innerText = textDict[lang].local;
    document.getElementById('lblCopy').innerText = textDict[lang].copy;
    document.getElementById('lblCopyDesc').innerText = textDict[lang].copyDesc;
}

function renderDate(isoDate) {
    const dateObj = new Date(isoDate);
    const localTime = dateObj.toLocaleString(currentLang, {
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit', timeZoneName: 'short'
    });
    document.getElementById('resLocalUpload').innerText = localTime;
}

function formatNumber(num) {
    if (!num || isNaN(num)) return textDict[currentLang].unknown;
    return parseInt(num).toLocaleString(currentLang);
}

function renderStats() {
    document.getElementById('resViews').innerText = formatNumber(rawStats.views);
    document.getElementById('resLikes').innerText = formatNumber(rawStats.likes);
    document.getElementById('resComments').innerText = formatNumber(rawStats.comments);
}

const checkBtn = document.getElementById('checkBtn');
const ytUrlInput = document.getElementById('ytUrl');
const loader = document.getElementById('loader');
const resultCard = document.getElementById('resultCard');
const langSwitcher = document.getElementById('langSwitcher');
const errorMsg = document.getElementById('errorMsg');
const warningMsg = document.getElementById('warningMsg');

checkBtn.addEventListener('click', async () => {
    const url = ytUrlInput.value.trim();

    if (!url) {
        showError(currentLang === 'id-ID' ? "Harap masukkan link YouTube yang valid." : "Please enter a valid YouTube link.");
        return;
    }

    resultCard.style.display = 'none';
    langSwitcher.style.display = 'none';
    errorMsg.style.display = 'none';
    warningMsg.style.display = 'none';
    loader.style.display = 'block';
    checkBtn.disabled = true;
    commentsFetchedForVideo = null;
    liveComments = [];

    try {
        const response = await fetch('?action=fetch_metadata', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url: url })
        });

        const rawText = await response.text();
        let data;
        try { data = JSON.parse(rawText); } catch (e) { throw new Error("Parse Error"); }

        if (data.error) {
            showError(data.error);
        } else {
            tampilkanHasil(data);
        }

    } catch (error) {
        showError(currentLang === 'id-ID' ? "Terjadi kesalahan koneksi atau perubahan pada server YouTube." : "A connection error occurred or YouTube server structure changed.");
    } finally {
        loader.style.display = 'none';
        checkBtn.disabled = false;
    }
});

function tampilkanHasil(data) {
    const vId = data.videoId || extractVideoId(document.getElementById('ytUrl').value);
    if (vId) prefetchComments(vId);

    const thumbImg = document.getElementById('resThumbnail');
    if (data.thumbnail) {
        thumbImg.src = data.thumbnail;
        thumbImg.style.display = 'block';
    } else {
        thumbImg.style.display = 'none';
    }

    document.getElementById('resTitle').innerText = data.title;
    document.getElementById('resGenre').innerText = data.genre;
    document.getElementById('resDesc').innerText = data.description ? data.description : "Deskripsi tidak dapat dimuat di server ini.";

    rawStats.views = data.views;
    rawStats.likes = data.likes;
    rawStats.comments = data.comments;
    renderStats();

    if (data.uploadDate) {
        globalRawDate = data.uploadDate;
        document.getElementById('resRawUpload').innerText = data.uploadDate;
        renderDate(data.uploadDate);
    } else {
        globalRawDate = null;
        document.getElementById('resRawUpload').innerText = "Data Upload tidak tersedia";
        document.getElementById('resLocalUpload').innerText = "-";
    }

    // BARU: Kartu Info Channel
    const channelCard = document.getElementById('channelCard');
    if (data.channelTitle && data.channelTitle !== 'Tidak diketahui') {
        channelCard.style.display = 'flex';
        document.getElementById('chAvatar').src = data.channelThumbnail || `https://ui-avatars.com/api/?name=${encodeURIComponent(data.channelTitle)}&background=random`;
        document.getElementById('chName').innerText = data.channelTitle;

        const d = textDict[currentLang];
        let statsText = '';
        if (data.subscriberHidden) {
            statsText = `${d.subsHidden} subscriber`;
        } else if (data.subscriberCount) {
            statsText = `${formatNumber(data.subscriberCount)} ${d.subscribers}`;
        }
        if (data.channelVideoCount) {
            statsText += (statsText ? ' • ' : '') + `${formatNumber(data.channelVideoCount)} ${d.videos}`;
        }
        document.getElementById('chStats').innerText = statsText || '-';
    } else {
        channelCard.style.display = 'none';
    }

    // BARU: Warning (misalnya kuota API habis)
    if (data.apiWarning) {
        warningMsg.innerText = '⚠️ ' + data.apiWarning;
        warningMsg.style.display = 'block';
    }

    // BARU: Simpan ke riwayat pencarian
    if (vId) {
        saveHistory({
            videoId: vId,
            title: data.title && data.title !== 'Tidak diketahui' ? data.title : vId,
            thumbnail: data.thumbnail || `https://i.ytimg.com/vi/${vId}/default.jpg`
        });
    }

    resultCard.style.display = 'block';
    langSwitcher.style.display = 'flex';
    document.getElementById('randomRow').style.display = 'flex';
}

function showError(msg) {
    errorMsg.innerText = msg;
    errorMsg.style.display = 'block';
}

const copyBtn = document.getElementById('copyBtn');

copyBtn.addEventListener('click', () => {
    const title = document.getElementById('resTitle').innerText;
    const views = document.getElementById('resViews').innerText;
    const likes = document.getElementById('resLikes').innerText;
    const comments = document.getElementById('resComments').innerText;
    const genre = document.getElementById('resGenre').innerText;
    const localDate = document.getElementById('resLocalUpload').innerText;
    const desc = document.getElementById('resDesc').innerText;
    const chName = document.getElementById('chName').innerText;

    const d = textDict[currentLang];

    const textToCopy =
`${d.title}: ${title}
Channel: ${chName}
${d.views}: ${views}
${d.likes}: ${likes}
${d.comments}: ${comments}
${d.genre}: ${genre}
${d.local}: ${localDate}

--- ${d.desc} ---
${desc}`;

    if (title !== '-') {
        navigator.clipboard.writeText(textToCopy).then(() => {
            const iconHtml = currentLang === 'id-ID' ? '✅ Berhasil Disalin!' : '✅ Copied Successfully!';
            const originalHtml = copyBtn.innerHTML;

            copyBtn.innerHTML = iconHtml;
            copyBtn.classList.add('success');

            setTimeout(() => {
                copyBtn.innerHTML = originalHtml;
                copyBtn.classList.remove('success');
            }, 2000);
        }).catch(err => {
            alert('Copy failed / Clipboard API blocked.');
        });
    }
});

// BARU: Tombol copy khusus untuk Deskripsi Video saja
const copyDescBtn = document.getElementById('copyDescBtn');
copyDescBtn.addEventListener('click', () => {
    const desc = document.getElementById('resDesc').innerText;
    if (!desc || desc === '-') return;

    navigator.clipboard.writeText(desc).then(() => {
        const d = textDict[currentLang];
        const original = copyDescBtn.innerHTML;
        copyDescBtn.innerHTML = `✅ <span>${d.copiedDesc}</span>`;
        copyDescBtn.classList.add('success');
        setTimeout(() => {
            copyDescBtn.innerHTML = original;
            copyDescBtn.classList.remove('success');
        }, 1500);
    }).catch(() => {
        alert('Copy failed / Clipboard API blocked.');
    });
});
</script>
</body>
</html>
