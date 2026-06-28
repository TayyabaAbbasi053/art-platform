<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

// Auth guard
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

 $adminName = $_SESSION['name'] ?? 'Admin';
 $toast = '';

// ── Handle POST actions ──────────────────────────────────

// Add new category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $name = trim($_POST['name'] ?? '');
    if (!$name) {
        $toast = 'Category name is required.';
    } else {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        $check = $conn->prepare("SELECT id FROM categories WHERE name = ? OR slug = ? LIMIT 1");
        $check->bind_param('ss', $name, $slug);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $toast = 'A category with this name already exists.';
        } else {
            $stmt = $conn->prepare("INSERT INTO categories (name, slug) VALUES (?, ?)");
            $stmt->bind_param('ss', $name, $slug);
            $stmt->execute();
            $toast = 'Category "' . htmlspecialchars($name) . '" added.';
        }
    }
}

// Edit category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if (!$id || !$name) {
        $toast = 'Invalid request.';
    } else {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        $check = $conn->prepare("SELECT id FROM categories WHERE (name = ? OR slug = ?) AND id != ? LIMIT 1");
        $check->bind_param('ssi', $name, $slug, $id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $toast = 'Another category with this name already exists.';
        } else {
            $stmt = $conn->prepare("UPDATE categories SET name = ?, slug = ? WHERE id = ?");
            $stmt->bind_param('ssi', $name, $slug, $id);
            $stmt->execute();
            $toast = 'Category updated.';
        }
    }
}

// Delete category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        $toast = 'Invalid request.';
    } else {
        // Check if artworks use this category
        $check = $conn->prepare("SELECT COUNT(*) FROM artworks WHERE category_id = ?");
        $check->bind_param('i', $id);
        $check->execute();
        $count = (int)$check->get_result()->fetch_row()[0];
        if ($count > 0) {
            $toast = "Cannot delete — {$count} artwork" . ($count > 1 ? 's' : '') . " use this category.";
        } else {
            $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$toast = 'Category deleted.';
            $toast = 'Category deleted.';
        }
    }
}

// ── Fetch all categories ─────────────────────────────────
 $categories = [];
 $res = $conn->query("
    SELECT c.id, c.name, c.slug, c.created_at,
           (SELECT COUNT(*) FROM artworks a WHERE a.category_id = c.id) AS artwork_count
    FROM categories c
    ORDER BY c.name ASC
");
while ($row = $res->fetch_assoc()) $categories[] = $row;

 $totalCategories = count($categories);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Categories — Art Bazaar Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    /* Design System Variables */
    --bg: #F6EDDE;
    --card: #F6EDDE;
    --sand: #DDCDAE;
    --border: #0C3F30;
    --ink: #0C3F30;
    --sidebar: 240px;
    --top: 60px;
}
html, body { height: 100%; background: var(--bg); color: var(--ink); font-family: 'DM Sans', sans-serif; }

/* Sidebar */
.sidebar {
    position: fixed; top: 0; left: 0; width: var(--sidebar); height: 100vh;
    background: var(--ink);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column; z-index: 100; overflow-y: auto;
}
.sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid var(--border); }
.sidebar-brand .logo-tag { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--sand); }
.sidebar-brand .logo-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--bg); margin-top: 2px; }
.sidebar-brand .logo-badge { display: inline-block; margin-top: 6px; background: var(--sand); color: var(--ink); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--sand); font-weight: 500; }
.nav-item {
    display: flex; align-items: center; gap: 10px; padding: 10px 20px;
    font-size: 12.5px; color: var(--bg); text-decoration: none; font-weight: 400;
    border-left: 2px solid transparent; transition: all .15s;
}
.nav-item:hover { color: var(--ink); background: var(--sand); border-left-color: var(--sand); }
.nav-item.active { color: var(--ink); background: var(--sand); border-left-color: var(--ink); font-weight: 500; }
.nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .8; stroke: var(--bg); }
.nav-item.active .icon, .nav-item:hover .icon { stroke: var(--ink); opacity: 1; }
.badge { margin-left: auto; background: var(--sand); color: var(--ink); font-size: 9px; font-weight: 600; padding: 1px 6px; border-radius: 20px; min-width: 18px; text-align: center; }
.badge.amber { background: var(--sand); }
.sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid var(--border); }
.signout-btn {
    display: flex; align-items: center; gap: 8px; padding: 9px 12px;
    font-size: 12px; color: var(--bg); text-decoration: none; border-radius: 8px;
    transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif;
}
.signout-btn:hover { background: var(--sand); color: var(--ink); }

/* Topbar */
.topbar {
    position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top);
    background: var(--ink); border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 99;
}
.topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--bg); }
.topbar-left .sub { font-size: 11px; color: var(--sand); margin-top: 1px; opacity: 0.8; }
.topbar-right { display: flex; align-items: center; gap: 20px; }
.admin-chip {
    display: flex; align-items: center; gap: 8px; background: var(--sand);
    border: 1px solid var(--border); padding: 5px 12px 5px 5px; border-radius: 30px;
}
.admin-chip .avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--bg); display: flex; align-items: center; justify-content: center; font-size: 11px; color: var(--ink); font-weight: 600; }
.admin-chip .name { font-size: 12px; color: var(--ink); font-weight: 500; }
.admin-chip .arrow { font-size: 12px; color: var(--ink); margin-left: 4px; opacity: 0.6; }

/* Main */
.main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
.content { padding: 32px; }

/* Page header with add button */
.page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.page-header .left .title { font-size: 11px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--ink); font-weight: 500; opacity: 0.7; }
.page-header .left .count { font-size: 12px; color: var(--ink); margin-top: 2px; }

/* Buttons */
.btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px;
    font-size: 12px; font-weight: 500; border-radius: 10px; border: none;
    cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .15s; text-decoration: none;
}
.btn-primary { background: var(--sand); color: var(--ink); }
.btn-primary:hover { background: #c4b69e; }
.btn-ghost { background: transparent; color: var(--ink); border: 1px solid var(--border); }
.btn-ghost:hover { border-color: var(--ink); color: var(--ink); background: var(--sand); }
.btn-danger { background: transparent; color: var(--ink); border: 1px solid var(--border); }
.btn-danger:hover { background: var(--sand); border-color: var(--ink); color: var(--ink); }
.btn-sm { padding: 5px 12px; font-size: 11px; border-radius: 7px; }

/* Toast */
.toast {
    background: var(--sand);
    color: var(--ink);
    border: 1px solid var(--border);
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 12.5px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.toast.error { background: var(--sand); color: var(--ink); border-color: var(--border); }
.toast.hidden { display: none; }
.toast-close { background: none; border: none; color: var(--ink); cursor: pointer; font-size: 16px; line-height: 1; opacity: 0.7; }
.toast-close:hover { opacity: 1; }

/* Table card */
.card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
.card table { border-radius: 0 0 14px 14px; overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--ink); font-weight: 500; padding: 12px 24px; text-align: left; border-bottom: 1px solid var(--border); background: var(--sand); opacity: 0.8; }
td { font-size: 12.5px; color: var(--ink); padding: 14px 24px; border-bottom: 1px solid var(--border); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--sand); box-shadow: 0 4px 12px rgba(12,63,48,.06); }
.td-name { color: var(--ink); font-weight: 500; }
.td-slug { font-size: 11px; color: var(--ink); font-family: monospace; opacity: 0.7; }
.td-count { font-weight: 600; color: var(--ink); }
.td-date { font-size: 11px; color: var(--ink); white-space: nowrap; opacity: 0.7; }
.td-actions { display: flex; gap: 6px; }
.td-empty { text-align: center; padding: 40px; color: var(--ink); font-size: 13px; opacity: 0.7; }

/* Modal overlay */
.modal-overlay {
    position: fixed; inset: 0; background: rgba(12,63,48,.4); z-index: 200;
    display: flex; align-items: center; justify-content: center;
    opacity: 0; pointer-events: none; transition: opacity .2s;
}
.modal-overlay.open { opacity: 1; pointer-events: auto; }
.modal {
    background: var(--card); border-radius: 16px; width: 420px; max-width: 92vw;
    box-shadow: 0 24px 60px rgba(12,63,48,.2); transform: translateY(12px); transition: transform .2s; border: 1px solid var(--border);
}
.modal-overlay.open .modal { transform: translateY(0); }
.modal-head { padding: 24px 28px 0; }
.modal-head h3 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--ink); }
.modal-head p { font-size: 12px; color: var(--ink); margin-top: 4px; opacity: 0.7; }
.modal-body { padding: 20px 28px; }
.modal-body label { display: block; font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--ink); font-weight: 500; margin-bottom: 6px; opacity: 0.7; }
.modal-body input {
    width: 100%; padding: 10px 14px; border: 1.5px solid var(--sand); border-radius: 10px;
    font-size: 14px; font-family: 'DM Sans', sans-serif; color: var(--ink); outline: none; transition: border-color .15s; background: var(--bg);
}
.modal-body input:focus { border-color: var(--ink); }
.modal-foot { padding: 0 28px 24px; display: flex; gap: 10px; justify-content: flex-end; }

/* Delete confirm modal */
.confirm-text { font-size: 13px; color: var(--ink); line-height: 1.6; padding: 4px 0; }
.confirm-text strong { color: var(--ink); }

/* Footer */
.dash-footer { padding: 20px 32px; border-top: 1px solid var(--border); font-size: 11px; color: var(--bg); margin-top: 12px; background: var(--ink); }

/* Hamburger Drawer */
#nav-drawer { display:none; position: fixed; top: 0; right: 0; width: 260px; height: 100vh; background: var(--ink); z-index: 200; transform: translateX(100%); transition: transform 0.3s ease; padding: 24px; display: flex; flex-direction: column; border-left: 1px solid var(--border); }
#nav-drawer.open { transform: translateX(0); display: flex; }
#nav-overlay { display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(12,63,48,0.4); z-index: 150; backdrop-filter: blur(2px); }
#nav-overlay.open { display: block; }
.ham-btn { display: none; flex-direction: column; gap: 5px; background: none; border: none; cursor: pointer; padding: 5px; width: 30px; }
.ham-btn span { width: 100%; height: 2px; background: var(--bg); border-radius: 2px; transition: 0.2s; }
.d-header { font-family: 'Playfair Display', serif; font-size: 18px; color: var(--bg); margin-bottom: 24px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
.d-link { color: var(--bg); text-decoration: none; font-size: 14px; padding: 12px 0; display: block; border-bottom: 1px solid rgba(246,237,222,0.1); font-family: 'DM Sans', sans-serif; }
.d-link:hover { color: var(--sand); padding-left: 5px; transition: 0.2s; }

/* Responsive */
@media (max-width: 1080px) {
    /* Tablet adjustments if needed */
}

@media (max-width: 768px) {
    :root { --sidebar: 0px; }
    .sidebar { display: none; }
    .topbar { left: 0; padding: 0 16px; }
    .content { padding: 16px; }
    
    /* Table to Card View */
    thead { display: none; }
    table, tbody, tr, td { display: block; width: 100%; }
    tr { background: var(--card); border: 1px solid var(--border); border-radius: 10px; margin-bottom: 12px; padding: 16px; box-shadow: 0 2px 4px rgba(12,63,48,.04); overflow: hidden; }
    td { padding: 8px 0; border-bottom: none; display: flex; justify-content: space-between; align-items: center; }
    td::before { content: attr(data-label); font-weight: 600; font-size: 11px; text-transform: uppercase; opacity: 0.6; color: var(--ink); }
    .td-actions { flex-direction: column; width: 100%; gap: 8px; margin-top: 12px; }
    .td-actions button { width: 100%; justify-content: center; }
    .td-empty { padding: 30px 16px; }
    
    .page-header { flex-direction: column; align-items: flex-start; gap: 12px; }
    .page-header .btn { width: 100%; justify-content: center; }
    
    .dash-footer { padding: 20px 16px; text-align: center; }
    
    .ham-btn { display: flex; }
    .admin-chip { display: none; }
}
</style>
</head>
<body>

<!-- ══════════════ SIDEBAR ══════════════ -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="logo-tag">Art Bazaar</div>
        <div class="logo-name">Dashboard</div>
        <span class="logo-badge">Admin</span>
    </div>
    <div class="sidebar-section">Overview</div>
    <a href="index.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Overview
    </a>
    <div class="sidebar-section">Content</div>
    <a href="artworks.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg>
        Artworks
    </a>
    <a href="artists.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        Artists
    </a>
    <a href="blogs.php" class="nav-item">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M7 8h10M7 12h6"/></svg>
    Blog Posts
</a>
    <a href="categories.php" class="nav-item active">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h10M4 18h7"/></svg>
        Categories
    </a>
    <div class="sidebar-section">Requests</div>
    <a href="inquiries.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
        Buyer Inquiries
    </a>
    <a href="commissions.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        Commissions
    </a>
    <a href="messages.php" class="nav-item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v13H4z"/><path d="M4 4l8 9 8-9"/></svg>
        Messages
    </a>
    <div class="sidebar-bottom">
        <a href="../../logout.php" class="signout-btn">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sign out
        </a>
    </div>
</aside>

<!-- ══════════════ TOPBAR ══════════════ -->
<header class="topbar">
    <div class="topbar-left">
        <h1>Categories</h1>
        <div class="sub">Manage artwork categories</div>
    </div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:12px;">
        <button class="ham-btn" id="hamBtn">
            <span></span><span></span><span></span>
        </button>
    </div>
</header>

<!-- ══════════════ MAIN ══════════════ -->
<main class="main">
<div class="content">

    <!-- Toast -->
    <?php if ($toast): ?>
    <div class="toast <?= strpos($toast, 'Cannot') !== false ? 'error' : '' ?>">
        <span><?= htmlspecialchars($toast) ?></span>
        <button class="toast-close" onclick="this.parentElement.classList.add('hidden')">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Page header -->
    <div class="page-header">
        <div class="left">
            <div class="title">All Categories</div>
            <div class="count"><?= $totalCategories ?> total</div>
        </div>
        <button class="btn btn-primary" onclick="openAddModal()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Category
        </button>
    </div>

    <!-- Table -->
    <div class="card">
        <?php if (empty($categories)): ?>
            <div class="td-empty">No categories yet. Click "Add Category" to create one.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Slug</th>
                    <th>Artworks</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($categories as $cat): ?>
                <tr>
                    <td class="td-name" data-label="Name"><?= htmlspecialchars($cat['name']) ?></td>
                    <td class="td-slug" data-label="Slug"><?= htmlspecialchars($cat['slug']) ?></td>
                    <td class="td-count" data-label="Artworks"><?= $cat['artwork_count'] ?></td>
                    <td class="td-date" data-label="Created"><?= date('d M Y', strtotime($cat['created_at'])) ?></td>
                    <td>
                        <div class="td-actions">
                            <button class="btn btn-ghost btn-sm" onclick="openEditModal(<?= $cat['id'] ?>, '<?= htmlspecialchars(addslashes($cat['name'])) ?>')">Edit</button>
                            <?php if ($cat['artwork_count'] == 0): ?>
                                <button class="btn btn-danger btn-sm" onclick="openDeleteModal(<?= $cat['id'] ?>, '<?= htmlspecialchars(addslashes($cat['name'])) ?>')">Delete</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>
<div class="dash-footer">Art Bazaar Admin Panel &mdash; <?= date('Y') ?></div>
</main>

<!-- HAMBURGER DRAWER HTML -->
<div id="nav-overlay"></div>
<div id="nav-drawer">
  <div class="d-header">Menu</div>
  <a href="index.php" class="d-link">Overview</a>
  <a href="artworks.php" class="d-link">Artworks</a>
  <a href="artists.php" class="d-link">Artists</a>
  <a href="categories.php" class="d-link">Categories</a>
  <a href="inquiries.php" class="d-link">Buyer Inquiries</a>
  <a href="commissions.php" class="d-link">Commissions</a>
  <a href="messages.php" class="d-link">Messages</a>
  <div style="margin-top:auto;border-top:1px solid rgba(246,237,222,0.1);padding-top:16px;">
    <a href="../../logout.php" class="d-link" style="color:#ff9999;">Sign Out</a>
  </div>
</div>

<!-- ══════════════ ADD MODAL ══════════════ -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-head">
            <h3>Add Category</h3>
            <p>Enter a name for the new category.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <label>Category Name</label>
                <input type="text" name="name" placeholder="e.g. Watercolor" required autofocus id="addInput">
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Category</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════ EDIT MODAL ══════════════ -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-head">
            <h3>Edit Category</h3>
            <p>Update the category name.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <div class="modal-body">
                <label>Category Name</label>
                <input type="text" name="name" id="editInput" required autofocus>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════ DELETE MODAL ══════════════ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-head">
            <h3>Delete Category</h3>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId">
            <div class="modal-body">
                <p class="confirm-text">Are you sure you want to delete <strong id="deleteName"></strong>? This action cannot be undone.</p>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn btn-danger">Yes, Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
// Drawer Logic
const hamBtn = document.getElementById('hamBtn');
const drawer = document.getElementById('nav-drawer');
const overlay = document.getElementById('nav-overlay');

if(hamBtn && drawer && overlay){
    hamBtn.addEventListener('click', () => {
        drawer.classList.add('open');
        overlay.classList.add('open');
    });
    overlay.addEventListener('click', () => {
        drawer.classList.remove('open');
        overlay.classList.remove('open');
    });
}

function openAddModal() {
    document.getElementById('addInput').value = '';
    document.getElementById('addModal').classList.add('open');
}
function openEditModal(id, name) {
    document.getElementById('editId').value = id;
    document.getElementById('editInput').value = name;
    document.getElementById('editModal').classList.add('open');
}
function openDeleteModal(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}
// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => {
        if (e.target === overlay) overlay.classList.remove('open');
    });
});
// Close modal on Escape key
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
    }
});
</script>
</body>
</html>