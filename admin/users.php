<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$users = db()->query("
    SELECT id, full_name, email, phone, role, created_at
    FROM users
    ORDER BY
      CASE role WHEN 'admin' THEN 0 ELSE 1 END,
      full_name ASC
")->fetchAll();

$adminPageTitle = 'Tenants & users';
$adminNav = 'users';
require_once __DIR__ . '/includes/header.php';
?>
<div class="admin-card">
    <h2>Registered accounts</h2>
    <p class="admin-muted" style="font-size:0.9rem;margin-top:0">Staff and residents who can sign in. For security, password changes are not shown here.</p>
    <div class="admin-table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">ID</th>
                    <th scope="col">Name</th>
                    <th scope="col">Email</th>
                    <th scope="col">Phone</th>
                    <th scope="col">Role</th>
                    <th scope="col">Since</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= (int)$u['id'] ?></td>
                    <td><?= h($u['full_name']) ?></td>
                    <td><?= h($u['email']) ?></td>
                    <td><?= h($u['phone']) ?></td>
                    <td><strong><?= h($u['role']) ?></strong></td>
                    <td><span class="admin-muted admin-muted--xs"><?= h(substr((string)$u['created_at'], 0, 10)) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
                <tr><td colspan="6">No users.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
