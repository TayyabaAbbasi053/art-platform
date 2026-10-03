<?php
/**
 * Point 72 — monthly "your art got views" email.
 *
 * Emails an artist ONLY when at least one of their artworks got
 * MIN_VIEWS (1,000) or more views in the previous calendar month.
 * Artists with no qualifying artwork get nothing.
 *
 * Run once a month via cPanel Cron Jobs, e.g. 08:00 on the 1st:
 *   0 8 1 * *  /usr/local/bin/php /home/<cpanel-user>/public_html/cron/monthly-views-email.php
 *
 * Test without sending anything:
 *   php monthly-views-email.php --dry-run
 * Re-run for a specific month:
 *   php monthly-views-email.php --month=2026-09
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

const MIN_VIEWS = 1000;                       // per-artwork threshold
const SITE_URL  = 'https://www.artbazaar.pk';
const MAIL_FROM = 'Art Bazaar <noreply@artbazaar.pk>';

require_once __DIR__ . '/../config/db.php';   // adjust if this file lives elsewhere

$opts   = getopt('', ['dry-run', 'month::']);
$dryRun = isset($opts['dry-run']);

// Period = previous calendar month unless --month=YYYY-MM is given
$period = (isset($opts['month']) && preg_match('/^\d{4}-\d{2}$/', $opts['month']))
    ? $opts['month']
    : date('Y-m', strtotime('first day of last month'));
$start = $period . '-01';
$end   = date('Y-m-d', strtotime("$start +1 month"));
$monthLabel = date('F Y', strtotime($start));

$sql = "
    SELECT a.artist_id, u.name AS artist_name, u.email, a.id AS artwork_id, a.title, COUNT(*) AS views
    FROM artwork_views v
    JOIN artworks a ON a.id = v.artwork_id
    JOIN users u    ON u.id = a.artist_id
    WHERE v.view_date >= ? AND v.view_date < ?
      AND u.status = 'active' AND u.email <> ''
    GROUP BY a.id
    HAVING views >= ?
    ORDER BY a.artist_id, views DESC";
$st = $conn->prepare($sql);
$min = MIN_VIEWS;
$st->bind_param('ssi', $start, $end, $min);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// Group by artist
$byArtist = [];
foreach ($rows as $r) {
    $byArtist[$r['artist_id']]['name']  = $r['artist_name'];
    $byArtist[$r['artist_id']]['email'] = $r['email'];
    $byArtist[$r['artist_id']]['art'][] = $r;
}

$sent = 0; $skipped = 0;
foreach ($byArtist as $artistId => $a) {
    // Already emailed for this month?
    $chk = $conn->prepare("SELECT 1 FROM reach_email_log WHERE artist_id = ? AND period = ?");
    $chk->bind_param('is', $artistId, $period);
    $chk->execute();
    $done = (bool)$chk->get_result()->fetch_row();
    $chk->close();
    if ($done) { $skipped++; continue; }

    $first = htmlspecialchars(explode(' ', trim($a['name']))[0]);
    $items = '';
    foreach ($a['art'] as $art) {
        $items .= '<li style="margin-bottom:6px;"><a href="' . SITE_URL . '/artwork-detail.php?id=' . (int)$art['artwork_id']
                . '" style="color:#0C3F30;font-weight:600;">' . htmlspecialchars($art['title']) . '</a> — '
                . number_format($art['views']) . ' views</li>';
    }
    $count   = count($a['art']);
    $subject = $count === 1
        ? "Your artwork got " . number_format($a['art'][0]['views']) . " views in $monthLabel"
        : "$count of your artworks got over " . number_format(MIN_VIEWS) . " views in $monthLabel";

    $body = '<div style="font-family:Arial,sans-serif;color:#0C3F30;background:#F6EDDE;padding:24px;max-width:560px;">'
          . "<h2 style=\"font-family:Georgia,serif;font-weight:400;\">Nice work, $first!</h2>"
          . "<p>People are noticing your art. In <strong>$monthLabel</strong>, these pieces each reached "
          . number_format(MIN_VIEWS) . "+ views:</p><ul style=\"padding-left:18px;\">$items</ul>"
          . '<p><a href="' . SITE_URL . '/dashboard/artist/index.php" style="display:inline-block;background:#0C3F30;color:#F6EDDE;'
          . 'padding:10px 18px;border-radius:6px;text-decoration:none;">See your full stats</a></p>'
          . '<p style="font-size:12px;opacity:.7;">Each person is counted once per day. — Art Bazaar</p></div>';

    $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: " . MAIL_FROM . "\r\n";

    if ($dryRun) {
        echo "[dry-run] would email {$a['email']}: $subject\n";
        continue;
    }

    if (mail($a['email'], $subject, $body, $headers)) {
        $log = $conn->prepare("INSERT IGNORE INTO reach_email_log (artist_id, period) VALUES (?, ?)");
        $log->bind_param('is', $artistId, $period);
        $log->execute();
        $log->close();
        $sent++;
    } else {
        error_log("monthly-views-email: mail() failed for artist $artistId");
    }
}

echo "Period $period — qualifying artists: " . count($byArtist) . ", sent: $sent, already sent: $skipped"
   . ($dryRun ? " (dry run)" : "") . "\n";
