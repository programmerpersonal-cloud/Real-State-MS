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
        <div class="card__header"><h3 class="card__title">Edit Profile</h3></div>
        <div class="card__body">
            <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
                <div class="form-grid--2">
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input class="form-control" name="full_name" value="<?= sanitize($u['full_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input class="form-control" type="email" name="email" value="<?= sanitize($u['email']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input class="form-control" name="phone" value="<?= sanitize($u['phone']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Avatar</label>
                        <input class="form-control" type="file" name="avatar" accept="image/*">
                    </div>
                </div>

                <div class="section-title">Change Password</div>
                <div class="form-grid--2">
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input class="form-control" type="password" name="new_password" placeholder="Leave blank to keep current">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <input class="form-control" type="password" name="confirm_password">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
