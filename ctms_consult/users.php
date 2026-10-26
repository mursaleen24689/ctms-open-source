<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';

require_login(['superadmin', 'admin']);
$users = $pdo->query('SELECT employee_id, full_name, role, lab_stream, designation,
                            ctms_user_id, is_active, is_deleted, last_login
                       FROM c_users ORDER BY is_deleted, full_name')->fetchAll();
$PAGE_TITLE = 'Users';
$PAGE_SUB = 'Consultancy account directory';
include __DIR__ . '/inc/layout_top.php';
?>
<div class="card">
  <h2>Accounts</h2>
  <p class="small muted" style="margin-bottom:12px">This page shows accounts that have signed into Consultancy. Accounts marked <strong>Main CTMS</strong> get their name, password, role, and active status from the main CTMS; make changes to those accounts there.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Name / login</th><th>Role</th><th>Lab / designation</th><th>Account source</th><th>Status</th><th>Last sign-in</th></tr></thead>
    <tbody>
    <?php foreach ($users as $row): ?>
      <tr>
        <td><strong><?= h($row['full_name']) ?></strong><div class="small muted"><?= h($row['employee_id']) ?></div></td>
        <td><?= h(ucfirst($row['role'])) ?></td>
        <td class="small"><?= h($row['lab_stream'] ?: '—') ?><div class="muted"><?= h($row['designation'] ?: '') ?></div></td>
        <td><?= $row['ctms_user_id'] ? badge('Main CTMS', 'blue') : badge('Consultancy', 'grey') ?></td>
        <td><?= (int)$row['is_active'] && !(int)$row['is_deleted'] ? badge('Active', 'green') : badge('Inactive', 'grey') ?></td>
        <td class="small"><?= $row['last_login'] ? h(date('d M Y H:i', strtotime($row['last_login']))) : 'Never' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$users): ?><tr><td colspan="6" class="empty">No user accounts have signed into Consultancy yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
