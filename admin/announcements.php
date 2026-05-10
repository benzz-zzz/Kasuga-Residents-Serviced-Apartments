<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (is_post() && verify_csrf($_POST['csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'add') {
        $title = trim((string)($_POST['title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $aud = (string)($_POST['audience'] ?? 'both');
        if (!in_array($aud, ['public', 'tenant', 'both'], true)) {
            $aud = 'both';
        }
        if (mb_strlen($title) < 2 || mb_strlen($title) > 160) {
            $_SESSION['flash_error'] = 'Title must be 2–160 characters.';
        } elseif (mb_strlen($body) < 5 || mb_strlen($body) > 5000) {
            $_SESSION['flash_error'] = 'Message must be 5–5000 characters.';
        } else {
            $now = db_timestamp();
            $ins = db()->prepare('
                INSERT INTO announcements (title, body, audience, is_active, sort_order, created_at, updated_at)
                VALUES (?, ?, ?, 1, 0, ?, ?)
            ');
            $ins->execute([$title, $body, $aud, $now, $now]);
            $_SESSION['flash_success'] = 'Announcement published.';
        }
    } elseif ($action === 'toggle' && ($id = (int)($_POST['id'] ?? 0)) > 0) {
        db()->prepare('UPDATE announcements SET is_active = 1 - (is_active & 1), updated_at = ? WHERE id = ?')->execute([db_timestamp(), $id]);
        $_SESSION['flash_success'] = 'Visibility updated.';
    } elseif ($action === 'delete' && ($id = (int)($_POST['id'] ?? 0)) > 0) {
        db()->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
        $_SESSION['flash_success'] = 'Announcement removed.';
    }
    redirect(admin_url('announcements.php'));
}

$list = db()->query('SELECT * FROM announcements ORDER BY sort_order ASC, id DESC')->fetchAll();

$adminPageTitle = 'Announcements';
$adminNav = 'announcements';
require_once __DIR__ . '/includes/header.php';
?>
<div class="admin-card" style="max-width:640px;margin-bottom:1.5rem">
    <h2>Post to website &amp; resident portal</h2>
    <p class="admin-muted" style="font-size:0.9rem;margin-top:0">Use for policy updates, maintenance windows, seasonal offers, or lobby notices.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
        <input type="hidden" name="action" value="add">
        <label class="admin-field-label" for="ann-title">Title</label>
        <input id="ann-title" name="title" required maxlength="160" style="width:100%;padding:0.5rem;box-sizing:border-box;border:1px solid #ccc;border-radius:6px">
        <label class="admin-field-label admin-field-label--spaced" for="ann-audience">Audience</label>
        <select id="ann-audience" name="audience" style="width:100%;padding:0.5rem;border-radius:6px">
            <option value="both">Public site + signed-in guests</option>
            <option value="public">Public site only</option>
            <option value="tenant">Signed-in guests only</option>
        </select>
        <label class="admin-field-label admin-field-label--spaced" for="ann-body">Message</label>
        <textarea id="ann-body" name="body" required rows="4" maxlength="5000" style="width:100%;padding:0.5rem;box-sizing:border-box;border:1px solid #ccc;border-radius:6px"></textarea>
        <button type="submit" class="admin-btn" style="margin-top:0.75rem">Publish</button>
    </form>
</div>

<div class="admin-card">
    <h2>All notices</h2>
    <div class="admin-table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Audience</th>
                    <th>Active</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($list as $row): ?>
                <tr>
                    <td><strong><?= h($row['title']) ?></strong><br><span class="admin-muted admin-muted--sm"><?= h(mb_substr((string)$row['body'], 0, 80)) ?><?= mb_strlen((string)$row['body']) > 80 ? '…' : '' ?></span></td>
                    <td><?= h($row['audience']) ?></td>
                    <td><?= (int)$row['is_active'] ? 'Yes' : 'No' ?></td>
                    <td style="white-space:nowrap">
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="admin-btn admin-btn--ghost" style="min-height:34px;padding:0.3rem 0.6rem"><?= (int)$row['is_active'] ? 'Hide' : 'Show' ?></button>
                        </form>
                        <form method="post" style="display:inline" onsubmit="return confirm('Delete this announcement?');">
                            <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="admin-btn admin-btn--ghost" style="min-height:34px;padding:0.3rem 0.6rem">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($list)): ?>
                <tr><td colspan="4">No announcements yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
