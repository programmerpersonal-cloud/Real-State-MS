<?php
/**
 * Profile — Index
 */
$u = $user;
?>
<div class="profile-grid">
    <div class="card profile-card">
        <img class="profile-card__avatar" src="<?= getAvatarUrl($u['avatar']) ?>" alt="">
        <div class="profile-card__name"><?= sanitize($u['full_name']) ?></div>
        <div class="profile-card__role"><?= sanitize($u['role_display']) ?></div>
        <div class="profile-meta">
            <div class="profile-meta__row"><span class="profile-meta__label">Email</span><span class="profile-meta__value"><?= sanitize($u['email']) ?></span></div>
            <div class="profile-meta__row"><span class="profile-meta__label">Username</span><span class="profile-meta__value"><?= sanitize($u['username']) ?></span></div>
            <div class="profile-meta__row"><span class="profile-meta__label">Phone</span><span class="profile-meta__value"><?= sanitize($u['phone'] ?: '—') ?></span></div>
            <div class="profile-meta__row"><span class="profile-meta__label">Last Login</span><span class="profile-meta__value"><?= $u['last_login_at'] ? formatDateTime($u['last_login_at']) : '—' ?></span></div>
            <div class="profile-meta__row"><span class="profile-meta__label">Member Since</span><span class="profile-meta__value"><?= formatDate($u['created_at']) ?></span></div>
        </div>
    </div>

    <div class="card">
        <div class="card__header"><h2 class="card__title">Edit Profile</h2></div>
        <div class="card__body">
            <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
                <div class="form-grid--2">
                    <div class="form-group">
                        <label class="form-label" for="pf-name">Full Name</label>
                        <input class="form-control" id="pf-name" name="full_name"
                               value="<?= sanitize($u['full_name']) ?>" autocomplete="name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="pf-email">Email</label>
                        <input class="form-control" id="pf-email" type="email" name="email"
                               value="<?= sanitize($u['email']) ?>" autocomplete="email" required>
                    </div>
                    <?php $phoneField = ['name' => 'phone', 'id' => 'pf-phone', 'label' => 'Phone',
                                        'value' => $u['phone'] ?? ''];
                          require VIEWS_PATH . '/components/ui/phone_field.php'; ?>
                    <div class="form-group">
                        <label class="form-label" for="pf-avatar">Avatar</label>
                        <input class="form-control" id="pf-avatar" type="file" name="avatar" accept="image/*">
                    </div>
                </div>

                <div class="section-title">Change Password</div>
                <div class="form-grid--2">
                    <div class="form-group">
                        <label class="form-label" for="pf-new">New Password</label>
                        <input class="form-control" id="pf-new" type="password" name="new_password"
                               autocomplete="new-password" placeholder="Leave blank to keep current">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="pf-confirm">Confirm New Password</label>
                        <input class="form-control" id="pf-confirm" type="password" name="confirm_password"
                               autocomplete="new-password">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
