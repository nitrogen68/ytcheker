<?php
// ==========================================
// KONFIGURASI GLOBAL
// ==========================================
// Letakkan API Key di sini agar bisa digunakan oleh semua fungsi
$GLOBAL_API_KEY = "AIzaSyCGncUjNMMAYDU0asdpEmzN0zxIFwMMmq4";


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

    // ==========================================================
    // 1. AMBIL STATISTIK VIA YOUTUBE DATA API V3 (Akurasi 100%)
    // ==========================================================
    $apiSuccess = false;

    // Memanggil Variabel Global
    if (!empty($videoId) && !empty($GLOBAL_API_KEY)) {
        $apiUrl = "https://www.googleapis.com/youtube/v3/videos?part=statistics&id={$videoId}&key={$GLOBAL_API_KEY}";
        
        // Mematikan warning jika gagal fetch URL
        $apiResponse = @file_get_contents($apiUrl);
        if ($apiResponse) {
            $apiData = json_decode($apiResponse, true);
            if (!empty($apiData['items'])) {
                $stats = $apiData['items'][0]['statistics'];
                $views    = $stats['viewCount'] ?? null;
                $likes    = $stats['likeCount'] ?? null;
                $comments = $stats['commentCount'] ?? null;
                
                $apiSuccess = true; // Tandai sukses agar Regex fallback tidak perlu dijalankan
            }
        }
    }

    // ==========================================================
    // 2. AMBIL METADATA LAINNYA VIA SCRAPING (Googlebot Spoofing)
    // ==========================================================
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    // Spoofing sebagai Googlebot
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7",
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"
    ]);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($html === false || $httpCode !== 200) {
        echo json_encode(['error' => 'Gagal mengambil data dari server YouTube.']);
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

    // --- EKSTRAKSI DESKRIPSI (Versi yang diperbaiki) ---
    $description = 'Tidak ada deskripsi.'; // Default

    // 1. Coba ambil dari JSON Shorts atau Video Standar (ytInitialData)
    // Pola ini mencari kunci "description" atau "shortDescription" secara lebih fleksibel
    if (preg_match('/"(?:shortD|d)escription":"(.*?)"(?=,"(?:title|isCrawlable)")/i', $html, $m)) {
        $descRaw = $m[1];
        $descRaw = str_replace(['\n', '\"', '\/'], ["\n", '"', '/'], $descRaw);
        
        // Coba decode JSON, jika gagal gunakan teks mentah
        $decoded = json_decode('"' . $descRaw . '"');
        $description = $decoded ?: $descRaw;
    } 
    
    // 2. Jika di atas gagal, coba ambil dari Meta Tag (Itemprop)
    elseif (preg_match('/<meta itemprop="description" content="([^"]+)">/i', $html, $m)) {
        $description = $m[1];
    } 
    
    // 3. Jika masih gagal, coba ambil dari OG Description
    elseif (preg_match('/<meta property="og:description" content="([^"]+)">/i', $html, $m)) {
        $description = $m[1];
    }



    // ==========================================================
    // 3. FALLBACK REGEX (Bekerja otomatis jika API Key kosong/gagal)
    // ==========================================================
    if (!$apiSuccess) {
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
    // 4. KIRIM HASIL KE FRONT-END (UI)
    // ==========================================================
    echo json_encode([
        'success'       => true,
        'title'         => html_entity_decode($title),
        'uploadDate'    => $uploadDate,
        'datePublished' => $datePublished,
        'genre'         => html_entity_decode($genre),
        'thumbnail'     => $thumbnail,
        'views'         => $views,
        'likes'         => $likes,
        'comments'      => $comments,
        'description'   => html_entity_decode($description)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


// ==========================================
// KERNEL PHP (BACKEND) - GET RANDOM COMMENT
// ==========================================
if (isset($_GET['action']) && $_GET['action'] == 'get_random_comment') {
    header('Content-Type: application/json');
    
    $videoId = $_GET['videoId'] ?? '';
    // Gunakan $GLOBAL_API_KEY yang didefinisikan di atas
    if (empty($videoId) || empty($GLOBAL_API_KEY)) {
        echo json_encode(['error' => 'Video ID atau API Key tidak valid.']);
        exit;
    }

    $url = "https://www.googleapis.com/youtube/v3/commentThreads?part=snippet&videoId={$videoId}&maxResults=20&order=relevance&key={$GLOBAL_API_KEY}";
    
    $response = @file_get_contents($url);
    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data['items'])) {
            $commentsList = [];
            foreach($data['items'] as $item) {
                $c = $item['snippet']['topLevelComment']['snippet'];
                $commentsList[] = [
                    'author' => $c['authorDisplayName'],
                    'text'   => $c['textOriginal'],
                    'avatar' => $c['authorProfileImageUrl']
                ];
            }
            
            $randomKey = array_rand($commentsList);
            echo json_encode([
                'success' => true,
                'commentsList' => $commentsList, 
                'author'  => $commentsList[$randomKey]['author'],
                'text'    => $commentsList[$randomKey]['text'],
                'avatar'  => $commentsList[$randomKey]['avatar']
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo json_encode(['error' => 'Komentar tidak ditemukan.']);
    exit;
}

?>

<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YouTube Metadata Checker Pro</title>
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

        /* INPUT WRAPPER DENGAN PASTE BUTTON */
        .input-wrapper { position: relative; width: 100%; margin-bottom: 20px; }
        
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

        button.primary-btn { width: 100%; padding: 16px; background: var(--accent); color: #fff; border: none; border-radius: 12px; font-size: 16px; font-weight: 600; cursor: pointer; transition: transform 0.2s, background 0.3s; }
        button.primary-btn:hover { background: var(--accent-hover); transform: translateY(-2px); }
        button.primary-btn:disabled { background: #94a3b8; cursor: not-allowed; transform: none; }
        
        /* LANGUAGE SWITCHER */
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

        /* RESULT CARD */
        .result-card { display: none; margin-top: 20px; animation: fadeIn 0.5s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .thumbnail-container { width: 100%; border-radius: 16px; overflow: hidden; margin-bottom: 20px; border: 1px solid var(--border-color); aspect-ratio: 16 / 9; background: var(--item-bg); display: flex; align-items: center; justify-content: center; }
        .thumbnail-container img { width: 100%; height: 100%; object-fit: cover; }

        .details-grid { display: flex; flex-direction: column; gap: 12px; }
        
        /* Layout untuk baris yang berdampingan (Stats) */
        .stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        
        .result-item { padding: 16px; background: var(--item-bg); border: 1px solid var(--border-color); border-radius: 12px; display: flex; flex-direction: column; }
        .result-item.stat-box { align-items: center; text-align: center; padding: 12px; }
        
        .label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.8px; margin-bottom: 6px; }
        .value { font-size: 15px; font-weight: 500; color: var(--text-main); word-break: break-word; line-height: 1.5; }
        .value.highlight { color: var(--accent); font-weight: 600; font-size: 16px;}
        
        /* Styling Khusus Deskripsi */
        .desc-box {
            max-height: 150px;
            overflow-y: auto;
            background: var(--bg-input);
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            font-size: 13px;
            white-space: pre-wrap; /* Mempertahankan format baris baru (enter) */
            line-height: 1.6;
        }
        /* Kustomisasi Scrollbar untuk deskripsi */
        .desc-box::-webkit-scrollbar { width: 6px; }
        .desc-box::-webkit-scrollbar-track { background: transparent; }
        .desc-box::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 10px; }

        .copy-btn { margin-top: 20px; width: 100%; padding: 14px; background: transparent; color: var(--text-main); border: 2px solid var(--border-color); border-radius: 12px; font-size: 15px; font-weight: 600; cursor: pointer; transition: 0.3s; display: flex; justify-content: center; align-items: center; gap: 8px; }
        .copy-btn:hover { background: var(--item-bg); border-color: var(--text-muted); }
        .copy-btn.success { background: #10b981; color: white; border-color: #10b981; }

        @media(max-width: 600px) { 
            .container { padding: 25px 20px; } 
            h2 { font-size: 20px; } 
            .stats-row { grid-template-columns: 1fr; } /* Stack stats di layar kecil */
        }

/* GAYA TOMBOL RANDOM COMMENT */
.random-btn { 
    margin-top: 10px; 
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
    justify-content: center; 
    gap: 5px; 
    width: fit-content; 
    margin-left: auto; 
    margin-right: auto;
}
.random-btn:hover { background: #fca5a5; transform: scale(1.05); }
[data-theme="dark"] .random-btn { background: #7f1d1d; color: #fca5a5; }
[data-theme="dark"] .random-btn:hover { background: #991b1b; }

/* GAYA MODAL ANIMASI ACAK */
.modal-overlay { 
    position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
    background: rgba(0,0,0,0.7); display: none; align-items: center; justify-content: center; 
    z-index: 9999; backdrop-filter: blur(5px);
}
.modal-content { 
    background: var(--bg-card); width: 90%; max-width: 380px; 
    padding: 30px 20px; border-radius: 24px; text-align: center; 
    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 2px solid var(--border-color); 
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
        <input type="url" id="ytUrl" placeholder="Masukkan Link Video YouTube..." autocomplete="off" required>
        <button id="pasteBtn" class="paste-btn">📋 Paste</button>
    </div>
    
    <button id="checkBtn" class="primary-btn">Cek Metadata</button>
    
    <div id="langSwitcher" class="lang-switcher">
        <span class="lang-label" id="lblFormat">Format:</span>
        <button class="flag-btn active" data-lang="id-ID" title="Bahasa Indonesia">🇮🇩</button>
        <button class="flag-btn" data-lang="en-US" title="English">🇬🇧</button>
    </div>

    <div class="loader" id="loader">Menganalisis data dari YouTube...</div>
    <div class="error-msg" id="errorMsg"></div>

    <div class="result-card" id="resultCard">
        <div class="thumbnail-container">
            <img id="resThumbnail" src="" alt="Thumbnail YouTube">
        </div>

        <div class="details-grid">
            <div class="result-item">
                <span class="label" id="lblTitle">Judul Video</span>
                <span class="value" id="resTitle">-</span>
            </div>
            
            <!-- Statistik Grid (Views, Likes, Comments) -->
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
    <!-- TAMBAHKAN TOMBOL INI -->
    <button id="btnRandomComment" class="random-btn" style="display: none;">🎲 Get Random</button>
</div>

            </div>

            <div class="result-item">
                <span class="label" id="lblGenre">Genre / Kategori</span>
                <span class="value" id="resGenre">-</span>
            </div>
            <div class="result-item">
                <span class="label" id="lblDesc">Deskripsi Video</span>
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


<!-- MODAL RANDOM COMMENT -->
<div id="randomModal" class="modal-overlay">
    <div class="modal-content">
        <img id="animAvatar" src="https://ui-avatars.com/api/?name=?&background=random" class="modal-avatar" alt="Avatar">
        <h4 id="animAuthor" class="modal-author">Mengacak...</h4>
        <div id="animText" class="modal-text">Mencari pemenang yang beruntung... ⏳</div>
        <button id="btnCloseModal" class="close-modal-btn" onclick="closeRandomModal()" disabled>Tutup</button>
    </div>
</div>


<script>

// VARIABEL GLOBAL UNTUK MENYIMPAN VIDEO ID
let globalVideoId = ""; 

// Mencegat Video ID saat user mengklik "Cek Metadata"
// (TAMBAHKAN INI KE DALAM checkBtn.addEventListener('click', ...) YANG SUDAH ADA)
// const urlObj = new URL(url); // Parsing URL
// if (url.includes('v=')) globalVideoId = new URL(url).searchParams.get('v');
// else if (url.includes('youtu.be/')) globalVideoId = url.split('youtu.be/')[1].split('?')[0];

// Namun cara tergampang, tangkap saja video Id dari Regex JS:
// Fungsi Ekstrak ID Video (Support Shorts & URL Standar)
function extractVideoId(url) {
    const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=|shorts\/)([^#\&\?]*).*/;
    const match = url.match(regExp);
    return (match && match[2].length === 11) ? match[2] : null;
}


// ==== LOGIKA ANIMASI & FETCH RANDOM COMMENT ====
const btnRandom = document.getElementById('btnRandomComment');
const randomModal = document.getElementById('randomModal');
const animAvatar = document.getElementById('animAvatar');
const animAuthor = document.getElementById('animAuthor');
const animText = document.getElementById('animText');
const btnCloseModal = document.getElementById('btnCloseModal');

let spinInterval;


// Tambahkan variabel ini di bagian atas script
let liveComments = []; 

// Tambahkan function ini
async function getRandomDisplay(videoId) {
    try {
        const res = await fetch(`?action=get_random_comment&videoId=${videoId}&maxResults=10`);
        const data = await res.json();
        
        // Simpan 10 komentar ke dalam variabel global jika berhasil
        if (data.success && data.commentsList) {
            liveComments = data.commentsList;
        }
    } catch(e) {
        console.log("Gagal mengambil 10 komentar dummy.");
    }
}



btnRandom.addEventListener('click', async () => {
    // 1. Pastikan kita punya Video ID
    const url = document.getElementById('ytUrl').value;
    globalVideoId = extractVideoId(url);
    
    if (!globalVideoId) {
        alert("Video ID tidak ditemukan.");
        return;
    }

    // 2. Buka Modal & Reset State
    randomModal.style.display = 'flex';
    btnCloseModal.disabled = true;
    
    // 3. MULAI ANIMASI ACAK
    // Kita gunakan data dari liveComments yang sudah di-fetch saat tekan "Cek Metadata"
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

    try {
        // 4. Ambil pemenang asli dari server
        const res = await fetch(`?action=get_random_comment&videoId=${globalVideoId}`);
        const data = await res.json();
        
        // 5. PAKSA ANIMASI BERJALAN MINIMAL 5 DETIK
        setTimeout(() => {
            clearInterval(spinInterval);
            btnCloseModal.disabled = false;
            
            if (data.success) {
                animAuthor.innerText = "🏆 " + data.author;
                animText.innerHTML = `<strong>Komentar:</strong><br><br>"${data.text}"`;
                animAvatar.src = data.avatar;
            } else {
                animAuthor.innerText = "❌ Gagal";
                animText.innerText = data.error || "Gagal mengambil komentar acak.";
                animAvatar.src = "https://ui-avatars.com/api/?name=X&background=ff0000&color=fff";
            }
        }, 5000);

    } catch(e) {
        setTimeout(() => {
            clearInterval(spinInterval);
            btnCloseModal.disabled = false;
            animAuthor.innerText = "Error";
            animText.innerText = "Gangguan koneksi ke API.";
        }, 5000);
    }
}); // <--- PASTIKAN KURUNG KURAWAL INI ADA


function closeRandomModal() {
    randomModal.style.display = 'none';
}


    // ===== THEME SWITCHER =====
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

    // ===== PASTE BUTTON =====
    document.getElementById('pasteBtn').addEventListener('click', async () => {
        try {
            const text = await navigator.clipboard.readText();
            document.getElementById('ytUrl').value = text;
        } catch (err) {
            alert('Browser Anda belum memberikan izin Paste otomatis. Silakan tahan dan tempel manual (Ctrl+V).');
        }
    });

    // ===== MULTI-LANGUAGE SYSTEM =====
    let currentLang = 'id-ID';
    let globalRawDate = null; // Menyimpan tanggal ISO untuk dirender ulang
    let rawStats = { views: null, likes: null, comments: null }; // Simpan angka asli untuk re-format

    const textDict = {
        'id-ID': {
            format: 'Bahasa Format:',
            title: 'Judul Video',
            genre: 'Genre / Kategori',
            desc: 'Deskripsi Video',
            views: '👁️ Views',
            likes: '👍 Likes',
            comments: '💬 Comments',
            raw: 'Upload Date (Raw Source)',
            local: 'Waktu Upload (Lokal Anda)',
            copy: 'Salin Semua Detail',
            unknown: 'Tidak diketahui'
        },
        'en-US': {
            format: 'Language Format:',
            title: 'Video Title',
            genre: 'Genre / Category',
            desc: 'Video Description',
            views: '👁️ Views',
            likes: '👍 Likes',
            comments: '💬 Comments',
            raw: 'Upload Date (Raw Source)',
            local: 'Upload Time (Your Local Time)',
            copy: 'Copy All Details',
            unknown: 'Unknown'
        }
    };

    document.querySelectorAll('.flag-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            document.querySelectorAll('.flag-btn').forEach(b => b.classList.remove('active'));
            e.currentTarget.classList.add('active');
            
            currentLang = e.currentTarget.getAttribute('data-lang');
            applyLanguage(currentLang);
            
            if(globalRawDate) renderDate(globalRawDate);
            renderStats(); // Re-format numbers based on language locale
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

    // ===== MAIN LOGIC FETCH METADATA =====
    const checkBtn = document.getElementById('checkBtn');
    const ytUrlInput = document.getElementById('ytUrl');
    const loader = document.getElementById('loader');
    const resultCard = document.getElementById('resultCard');
    const langSwitcher = document.getElementById('langSwitcher');
    const errorMsg = document.getElementById('errorMsg');

    checkBtn.addEventListener('click', async () => {
        const url = ytUrlInput.value.trim();
        
        if (!url) {
            showError(currentLang === 'id-ID' ? "Harap masukkan link YouTube yang valid." : "Please enter a valid YouTube link.");
            return;
        }

        resultCard.style.display = 'none';
        langSwitcher.style.display = 'none';
        errorMsg.style.display = 'none';
        loader.style.display = 'block';
        checkBtn.disabled = true;

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
        // 1. Panggil fungsi untuk mengambil data komentar (Data akan masuk ke liveComments)
        const vId = extractVideoId(document.getElementById('ytUrl').value);
        if(vId) getRandomDisplay(vId);
        
        // 2. Thumbnail
        const thumbImg = document.getElementById('resThumbnail');
        if (data.thumbnail) {
            thumbImg.src = data.thumbnail;
            thumbImg.style.display = 'block';
        } else {
            thumbImg.style.display = 'none';
        }

        // 3. Update Text Content
        document.getElementById('resTitle').innerText = data.title;
        document.getElementById('resGenre').innerText = data.genre;
        document.getElementById('resDesc').innerText = data.description;
        
        // 4. Simpan raw stats
        rawStats.views = data.views;
        rawStats.likes = data.likes;
        rawStats.comments = data.comments;
        renderStats();

        // 5. Timing
        if (data.uploadDate) {
            globalRawDate = data.uploadDate; 
            document.getElementById('resRawUpload').innerText = data.uploadDate;
            renderDate(data.uploadDate);
        } else {
            globalRawDate = null;
            document.getElementById('resRawUpload').innerText = "Timestamp Failed.";
            document.getElementById('resLocalUpload').innerText = "-";
        }
        
        // 6. Tampilkan Result Card
        resultCard.style.display = 'block';
        langSwitcher.style.display = 'flex';
        document.getElementById('btnRandomComment').style.display = 'flex';

    } // <--- KURUNG TUTUP FUNGSI DI SINI, BUKAN DI ATAS



    function showError(msg) {
        errorMsg.innerText = msg;
        errorMsg.style.display = 'block';
    }

    // ===== COPY ALL DETAILS =====
    const copyBtn = document.getElementById('copyBtn');
    
    copyBtn.addEventListener('click', () => {
        const title = document.getElementById('resTitle').innerText;
        const views = document.getElementById('resViews').innerText;
        const likes = document.getElementById('resLikes').innerText;
        const comments = document.getElementById('resComments').innerText;
        const genre = document.getElementById('resGenre').innerText;
        const localDate = document.getElementById('resLocalUpload').innerText;
        const desc = document.getElementById('resDesc').innerText;

        const d = textDict[currentLang];

        const textToCopy = 
`${d.title}: ${title}
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
</script>

</body>
</html>
