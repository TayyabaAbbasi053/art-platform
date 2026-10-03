<?php
/**
 * track.php — records artwork views and artist-profile visits (point 71).
 * Place in the site root (next to config/, artworks.php ...).
 *
 * Usage (after config/db.php has been loaded, so $conn and the session exist):
 *   require_once __DIR__ . '/track.php';
 *   trackArtworkView($conn, $artworkId);   // in artwork-detail.php
 *   trackProfileVisit($conn, $artistId);   // in artist-profile.php
 *
 * Rules: one count per viewer per day, bots ignored, artist's own visits ignored,
 * and any failure is swallowed so a public page can never break because of tracking.
 */

function _trackViewerKey(): string {
    $salt = 'artbazaar-reach-v1';
    if (!empty($_SESSION['user_id'])) {
        return hash('sha256', 'u' . (int)$_SESSION['user_id'] . $salt);
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return hash('sha256', $ip . '|' . $ua . $salt);
}

function _trackIsBot(): bool {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') return true;
    return (bool)preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|curl|wget|python|headless/', $ua);
}

function trackArtworkView($conn, $artworkId): void {
    try {
        $artworkId = (int)$artworkId;
        if ($artworkId <= 0 || _trackIsBot()) return;

        // Find the owner so the artist's own views don't count
        $s = $conn->prepare("SELECT artist_id FROM artworks WHERE id = ? LIMIT 1");
        $s->bind_param('i', $artworkId);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$row) return;
        if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$row['artist_id']) return;

        $key = _trackViewerKey();
        $ins = $conn->prepare("INSERT IGNORE INTO artwork_views (artwork_id, viewer_key, view_date) VALUES (?, ?, CURDATE())");
        $ins->bind_param('is', $artworkId, $key);
        $ins->execute();
        $ins->close();
    } catch (Throwable $e) {
        error_log('trackArtworkView: ' . $e->getMessage());
    }
}

function trackProfileVisit($conn, $artistId): void {
    try {
        $artistId = (int)$artistId;
        if ($artistId <= 0 || _trackIsBot()) return;
        if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $artistId) return;

        $key = _trackViewerKey();
        $ins = $conn->prepare("INSERT IGNORE INTO profile_visits (artist_id, viewer_key, visit_date) VALUES (?, ?, CURDATE())");
        $ins->bind_param('is', $artistId, $key);
        $ins->execute();
        $ins->close();
    } catch (Throwable $e) {
        error_log('trackProfileVisit: ' . $e->getMessage());
    }
}
