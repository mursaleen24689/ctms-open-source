<?php
declare(strict_types=1);
/** Page shell — bottom. Closes what layout_top.php opened. */
$__u = current_user();
?>
<?php if ($__u): ?>
    </div><!-- /.body -->
  </main>
</div><!-- /.shell -->
<?php else: ?>
</div><!-- /.body -->
<?php endif; ?>
<div style="text-align:center;padding:16px;font-size:10.5px;color:var(--text-soft)">
  <?= h(setting('lab_name', 'Department of Materials Engineering')) ?> ·
  <?= h(setting('lab_full_name', 'Example Technical University')) ?> ·
  Consultancy Platform <?= h($CFG['app_version'] ?? '') ?>
</div>
</body>
</html>
