<?php
function fetchValorantAPI(string $url): ?array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $status !== 200) {
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Load every weapon and its skin catalogue from Valorant-API.
$api = fetchValorantAPI('https://valorant-api.com/v1/weapons');
$weapons = $api['data'] ?? [];

$selectedWeaponUuid = $_POST['weapon'] ?? '';
$selectedSkinUuid = $_POST['skin'] ?? '';
$error = '';
$selectedWeapon = null;
$selectedSkin = null;
$skinImages = [];
$skinVideos = [];

if (!$api || !is_array($weapons) || empty($weapons)) {
    $error = 'Could not load the Valorant catalogue. Check your connection and try again.';
}

// Validate selected weapon against the API response.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $error === '') {
    foreach ($weapons as $weapon) {
        if (($weapon['uuid'] ?? '') === $selectedWeaponUuid) {
            $selectedWeapon = $weapon;
            break;
        }
    }

    if (!$selectedWeapon) {
        $error = 'Please choose a valid weapon.';
    } else {
        foreach (($selectedWeapon['skins'] ?? []) as $skin) {
            if (($skin['uuid'] ?? '') === $selectedSkinUuid) {
                $selectedSkin = $skin;
                break;
            }
        }

        if (!$selectedSkin) {
            $error = 'Please choose a skin available for the selected weapon.';
        }
    }

    if ($error === '' && $selectedSkin) {
        $skinName = $selectedSkin['displayName'] ?? 'Selected skin';

        // Main skin image
        if (!empty($selectedSkin['displayIcon'])) {
            $skinImages[] = [
                'name' => $skinName . ' — Main image',
                'type' => 'Main skin',
                'url' => $selectedSkin['displayIcon'],
            ];
        }

        // Upgrade level images and videos
        foreach (($selectedSkin['levels'] ?? []) as $index => $level) {
            $levelName = $level['displayName'] ?? ('Level ' . ($index + 1));

            if (!empty($level['displayIcon'])) {
                $skinImages[] = [
                    'name' => $levelName,
                    'type' => 'Upgrade level ' . ($index + 1),
                    'url' => $level['displayIcon'],
                ];
            }

            if (!empty($level['streamedVideo'])) {
                $skinVideos[] = [
                    'name' => $levelName,
                    'type' => 'Upgrade level ' . ($index + 1),
                    'url' => $level['streamedVideo'],
                ];
            }
        }

        // Chroma images and videos
        foreach (($selectedSkin['chromas'] ?? []) as $index => $chroma) {
            $chromaName = $chroma['displayName'] ?? ('Variant ' . ($index + 1));
            $chromaImage = $chroma['fullRender'] ?? $chroma['displayIcon'] ?? '';

            if ($chromaImage !== '') {
                $skinImages[] = [
                    'name' => $chromaName,
                    'type' => 'Chroma variant ' . ($index + 1),
                    'url' => $chromaImage,
                ];
            }

            if (!empty($chroma['streamedVideo'])) {
                $skinVideos[] = [
                    'name' => $chromaName,
                    'type' => 'Chroma variant ' . ($index + 1),
                    'url' => $chroma['streamedVideo'],
                ];
            }
        }
    }
}

// Build a compact weapon => skins map for the browser's dependent skin dropdown.
$catalogueForBrowser = [];
foreach ($weapons as $weapon) {
    $weaponUuid = $weapon['uuid'] ?? '';
    if ($weaponUuid === '') {
        continue;
    }

    $catalogueForBrowser[$weaponUuid] = [];
    foreach (($weapon['skins'] ?? []) as $skin) {
        $catalogueForBrowser[$weaponUuid][] = [
            'uuid' => $skin['uuid'] ?? '',
            'name' => $skin['displayName'] ?? 'Unnamed skin',
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Valorant Skin Viewer</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <header class="page-header">
        <p class="eyebrow">WEAPON COSMETICS DATABASE</p>
        <h1>VALORANT <span>SKIN VIEWER</span></h1>
        <p class="header-description">Browse every weapon and the skins available for it.</p>
    </header>

    <form action="index.php" method="post" class="selection-panel" id="selectionForm">
        <div class="selection-groups">
            <div class="selection-group">
                <label for="weaponSelect">Select weapon</label>
                <select name="weapon" id="weaponSelect" required>
                    <option value="">Choose a weapon</option>
                    <?php foreach ($weapons as $weapon): ?>
                        <option
                            value="<?= h($weapon['uuid'] ?? '') ?>"
                            <?= $selectedWeaponUuid === ($weapon['uuid'] ?? '') ? 'selected' : '' ?>
                        >
                            <?= h($weapon['displayName'] ?? 'Unnamed weapon') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="selection-group">
                <label for="skinSelect">Select skin</label>
                <select name="skin" id="skinSelect" required <?= $selectedWeaponUuid === '' ? 'disabled' : '' ?>>
                    <option value="">Choose a weapon first</option>
                </select>
                <p class="field-hint">Skin choices update when you change the weapon.</p>
            </div>
        </div>

        <div class="actions">
            <button type="submit" name="confirm" class="btn btn-primary">Explore skin</button>
            <button type="button" class="btn btn-secondary" onclick="resetViewer()">Reset</button>
        </div>
    </form>

    <main id="result" aria-live="polite">
        <?php if ($error !== ''): ?>
            <div class="message"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($selectedWeapon && $selectedSkin && $error === ''): ?>
            <section class="result-header">
                <p class="eyebrow">SELECTED LOADOUT</p>
                <h2><?= h($selectedSkin['displayName'] ?? 'Selected skin') ?></h2>
                <p><?= h($selectedWeapon['displayName'] ?? '') ?> <span class="separator">/</span> Skin preview</p>
            </section>

            <?php if (!empty($skinImages)): ?>
                <section class="content-section">
                    <div class="section-header">
                        <div>
                            <p class="eyebrow">VISUAL COLLECTION</p>
                            <h2>Skin images &amp; variants</h2>
                        </div>
                        <span class="item-count"><?= count($skinImages) ?> images</span>
                    </div>

                    <div class="image-grid">
                        <?php foreach ($skinImages as $item): ?>
                            <article class="media-card image-card">
                                <div class="media-frame">
                                    <img src="<?= h($item['url']) ?>" alt="<?= h($item['name']) ?>" loading="lazy">
                                </div>
                                <div class="card-caption">
                                    <span class="card-type"><?= h($item['type']) ?></span>
                                    <h3><?= h($item['name']) ?></h3>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php else: ?>
                <div class="message">No skin images are available for this item.</div>
            <?php endif; ?>

            <?php if (!empty($skinVideos)): ?>
                <section class="content-section">
                    <div class="section-header">
                        <div>
                            <p class="eyebrow">MOTION &amp; AUDIO</p>
                            <h2>Video previews</h2>
                            <p class="section-note">
                                Previews are queued: one plays at a time, once each. Autoplay with sound may be blocked by your browser.
                                Use the sound button to enable audio at 60%.
                            </p>
                        </div>
                        <span class="item-count"><?= count($skinVideos) ?> videos</span>
                    </div>

                    <div class="video-grid" id="videoGrid">
                        <?php foreach ($skinVideos as $index => $item): ?>
                            <article class="media-card video-card">
                                <div class="video-frame">
                                    <video
                                        class="skin-video"
                                        controls
                                        playsinline
                                        preload="<?= $index === 0 ? 'auto' : 'none' ?>"
                                        data-video-index="<?= (int) $index ?>"
                                    >
                                        <source src="<?= h($item['url']) ?>" type="video/mp4">
                                        Your browser does not support HTML video.
                                    </video>
                                </div>
                                <div class="card-caption">
                                    <span class="card-type"><?= h($item['type']) ?></span>
                                    <h3><?= h($item['name']) ?></h3>
                                    <?php if ($index === 0): ?>
                                        <button type="button" class="sound-btn" onclick="enableVideoSound(this)">
                                            Enable sound · 60%
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <footer>
        <p>Fan-made viewer using community Valorant API assets. Not affiliated with Riot Games.</p>
    </footer>
</div>

<script>
const skinCatalogue = <?= json_encode(
    $catalogueForBrowser,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
) ?>;

const weaponSelect = document.getElementById('weaponSelect');
const skinSelect = document.getElementById('skinSelect');
const initiallySelectedSkin = <?= json_encode($selectedSkinUuid, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

function populateSkins(weaponUuid, selectedSkinUuid = '') {
    skinSelect.innerHTML = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = weaponUuid ? 'Choose a skin' : 'Choose a weapon first';
    skinSelect.appendChild(placeholder);

    const skins = skinCatalogue[weaponUuid] || [];

    skins.forEach(skin => {
        const option = document.createElement('option');
        option.value = skin.uuid;
        option.textContent = skin.name;
        if (skin.uuid === selectedSkinUuid) {
            option.selected = true;
        }
        skinSelect.appendChild(option);
    });

    skinSelect.disabled = !weaponUuid || skins.length === 0;
}

weaponSelect.addEventListener('change', () => {
    populateSkins(weaponSelect.value);
});

if (weaponSelect.value) {
    populateSkins(weaponSelect.value, initiallySelectedSkin);
}

function resetViewer() {
    window.location.href = window.location.pathname;
}

// Browser autoplay policies generally prohibit audible autoplay.
// Start the first preview muted, then allow the user to enable 60% audio.
function enableVideoSound(button) {
    const video = document.querySelector('.skin-video:not([data-finished="true"])');
    if (!video) return;

    video.volume = 0.6;
    video.muted = false;
    video.play().then(() => {
        button.textContent = 'Sound enabled · 60%';
        button.disabled = true;
    }).catch(() => {
        button.textContent = 'Press play to enable sound';
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const videos = Array.from(document.querySelectorAll('.skin-video'));
    if (videos.length === 0) return;

    videos.forEach((video, index) => {
        video.volume = 0.6;
        video.loop = false;

        video.addEventListener('play', () => {
            videos.forEach(other => {
                if (other !== video && !other.paused) other.pause();
            });
        });

        video.addEventListener('ended', () => {
            video.dataset.finished = 'true';
            const nextVideo = videos[index + 1];
            if (nextVideo) {
                nextVideo.volume = 0.6;
                nextVideo.muted = true;
                nextVideo.play().catch(() => {
                    // User can play the next preview manually if autoplay is blocked.
                });
            }
        });
    });

    // Only the first preview is auto-started; later previews queue after it ends.
    videos[0].muted = true;
    videos[0].play().catch(() => {
        // Native controls remain available if autoplay is blocked.
    });
});
</script>
</body>
</html>
