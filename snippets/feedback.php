<?php
/**
 * snippets/feedback.php
 * Flash message renderer — used by auth + app templates.
 */
?>
<?php if (!empty($_SESSION['error'])) { ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= h($_SESSION['error']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php $_SESSION['error'] = null; } ?>

<?php if (!empty($_SESSION['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= h($_SESSION['success']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php $_SESSION['success'] = null; } ?>

<?php if (!empty($_SESSION['warning'])) { ?>
<div class="alert alert-warning alert-dismissible fade show" role="alert">
    <?= h($_SESSION['warning']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php $_SESSION['warning'] = null; } ?>

<?php if (!empty($_SESSION['info'])) { ?>
<div class="alert alert-info alert-dismissible fade show" role="alert">
    <?= h($_SESSION['info']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php $_SESSION['info'] = null; } ?>
