<?php
/**
 * Notify every follower of an artist about a new artwork.
 *
 * Usage (in the artist upload code, right after the artwork is saved as
 * 'active' - or in the admin approval code if uploads need approval first):
 *
 *   require_once __DIR__ . '/follow-helpers.php';   // adjust path
 *   notifyFollowersOfNewArtwork($conn, $artistId, $newArtworkId, $title);
 *
 * Returns the number of followers notified. Safe to call twice for the same
 * artwork - nobody gets a duplicate.
 */
function notifyFollowersOfNewArtwork(mysqli $conn, int $artistId, int $artworkId, string $title): int
{
    $st = $conn->prepare("SELECT name FROM users WHERE id = ?");
    $st->bind_param('i', $artistId); $st->execute();
    $artist = $st->get_result()->fetch_assoc();
    if (!$artist) return 0;

    $nTitle = mb_substr('New artwork from ' . $artist['name'], 0, 160);
    $nMsg   = mb_substr('"' . $title . '" was just uploaded.', 0, 255);
    $link   = 'artwork-detail.php?id=' . $artworkId;

    $st = $conn->prepare(
        "INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
         SELECT f.buyer_id, 'new_artwork', ?, ?, ?, 0, NOW()
         FROM artist_followers f
         WHERE f.artist_id = ?
           AND NOT EXISTS (SELECT 1 FROM notifications n
                           WHERE n.user_id = f.buyer_id AND n.type = 'new_artwork' AND n.link = ?)"
    );
    $st->bind_param('sssis', $nTitle, $nMsg, $link, $artistId, $link);
    $st->execute();
    return $st->affected_rows;
}
