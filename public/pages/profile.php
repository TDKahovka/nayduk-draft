<?php
/* ============================================
   НАЙДУК — Страница профиля пользователя
   Версия 3.0 (апрель 2026)
   - Редактирование профиля, аватар, 2FA
   - Отображение рейтинга, статистики
   - Ссылка на бизнес-кабинет (если is_business = 1)
   ============================================ */

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/ImageOptimizer.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_auth'] = '/profile';
    header('Location: /auth/login');
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();
$userId = (int)$_SESSION['user_id'];
$user = $db->getUserById($userId);
if (!$user || !empty($user['deleted_at'])) {
    session_destroy();
    header('Location: /auth/login');
    exit;
}

// Автосоздание недостающих полей (гарантия)
$columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
$requiredFields = ['phone', 'avatar_url', 'is_partner', 'trust_score', 'notify_email', 'notify_sms', 'deleted_at', 'delete_token', 'phone_visible', 'telegram', 'whatsapp', 'city', 'is_business'];
foreach ($requiredFields as $field) {
    if (!in_array($field, $columns)) {
        $type = match($field) {
            'is_partner' => 'BOOLEAN DEFAULT FALSE',
            'is_business' => 'BOOLEAN DEFAULT FALSE',
            'trust_score' => 'INT DEFAULT 0',
            'notify_email' => 'BOOLEAN DEFAULT TRUE',
            'notify_sms' => 'BOOLEAN DEFAULT FALSE',
            'deleted_at' => 'TIMESTAMP NULL',
            'delete_token' => 'VARCHAR(255)',
            'phone_visible' => 'BOOLEAN DEFAULT FALSE',
            'telegram' => 'VARCHAR(100)',
            'whatsapp' => 'VARCHAR(100)',
            'city' => 'VARCHAR(255)',
            default => 'TEXT'
        };
        $pdo->exec("ALTER TABLE users ADD COLUMN $field $type");
    }
}

$isBusiness = !empty($user['is_business']);
$isPartner = !empty($user['is_partner']);

// Статистика
$listingsCount = $db->fetchCount("SELECT COUNT(*) FROM listings WHERE user_id = ?", [$userId]);
$favoritesCount = $db->fetchCount("SELECT COUNT(*) FROM favorites WHERE user_id = ?", [$userId]);
$messagesUnread = $db->fetchCount("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0", [$userId]);

// Рейтинг
$userRating = $db->getUserRating($userId);
$avgRating = $userRating ? $userRating['avg_rating'] : 0;
$totalReviews = $userRating ? $userRating['total_reviews'] : 0;

$csrfToken = generateCsrfToken();

$pageTitle = 'Мой профиль — Найдук';
$pageDescription = 'Личный кабинет пользователя';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    <style>
        .profile-container { max-width: 1000px; margin: 40px auto; padding: 0 20px; }
        .profile-card { background: var(--surface); border-radius: var(--radius-xl); padding: 30px; border: 1px solid var(--border); box-shadow: var(--shadow-md); }
        .profile-header { display: flex; gap: 30px; flex-wrap: wrap; margin-bottom: 30px; }
        .profile-avatar { text-align: center; }
        .profile-avatar img, .avatar-placeholder { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 3px solid var(--primary); background: var(--bg-secondary); }
        .avatar-placeholder { display: flex; align-items: center; justify-content: center; font-size: 48px; color: var(--text-secondary); }
        .profile-info { flex: 1; }
        .profile-name { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
        .profile-email { color: var(--text-secondary); margin-bottom: 12px; }
        .trust-bar { height: 8px; background: var(--bg-secondary); border-radius: var(--radius-full); overflow: hidden; margin: 12px 0; width: 200px; }
        .trust-fill { height: 100%; background: linear-gradient(90deg, var(--success), var(--primary)); width: <?= min($user['trust_score'] ?? 0, 100) ?>%; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 16px; margin: 20px 0; }
        .stat-card { background: var(--bg-secondary); border-radius: var(--radius); padding: 12px; text-align: center; }
        .stat-value { font-size: 24px; font-weight: 700; color: var(--primary); }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-weight: 600; margin-bottom: 8px; }
        .form-input, .form-textarea { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--bg); color: var(--text); }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: var(--radius-full); font-weight: 600; cursor: pointer; border: none; transition: all var(--transition); }
        .btn-primary { background: var(--primary); color: white; }
        .btn-secondary { background: var(--bg-secondary); border: 1px solid var(--border); color: var(--text); }
        .btn-danger { background: rgba(255,59,48,0.1); color: var(--danger); }
        .form-actions { display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap; }
        .business-link { margin-top: 20px; text-align: center; }
        @media (max-width: 768px) {
            .profile-header { flex-direction: column; align-items: center; text-align: center; }
            .trust-bar { margin-left: auto; margin-right: auto; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<div class="profile-container">
    <div class="profile-card">
        <div class="profile-header">
            <div class="profile-avatar">
                <?php if (!empty($user['avatar_url'])): ?>
                    <img src="<?= htmlspecialchars($user['avatar_url']) ?>" alt="Аватар" id="avatar-img">
                <?php else: ?>
                    <div class="avatar-placeholder" id="avatar-placeholder">👤</div>
                <?php endif; ?>
                <button class="btn btn-secondary btn-small" id="change-avatar-btn" style="margin-top: 12px;">Изменить аватар</button>
                <input type="file" id="avatar-file" style="display: none;" accept="image/jpeg,image/png,image/webp">
            </div>
            <div class="profile-info">
                <h1 class="profile-name"><?= htmlspecialchars($user['name'] ?? 'Пользователь') ?></h1>
                <div class="profile-email"><?= htmlspecialchars($user['email']) ?></div>
                <div class="trust-bar"><div class="trust-fill"></div></div>
                <div>Доверие: <?= $user['trust_score'] ?? 0 ?>/100</div>
                <div>⭐ Рейтинг: <?= number_format($avgRating, 1) ?> (<?= $totalReviews ?> отзывов)</div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?= $listingsCount ?></div><div>Объявлений</div></div>
            <div class="stat-card"><div class="stat-value"><?= $favoritesCount ?></div><div>Избранное</div></div>
            <div class="stat-card"><div class="stat-value"><?= $messagesUnread ?></div><div>Сообщения</div></div>
        </div>

        <?php if ($isBusiness): ?>
        <div class="business-link">
            <a href="/business/dashboard" class="btn btn-primary">🏢 Мой магазин</a>
        </div>
        <?php endif; ?>

        <hr style="margin: 30px 0;">

        <h2>Настройки профиля</h2>
        <form id="profile-form">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <div class="form-group">
                <label class="form-label">Имя</label>
                <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($user['email']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Телефон</label>
                <input type="tel" name="phone" class="form-input" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                <div class="form-hint">Будет виден в объявлениях, если включить галочку</div>
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="phone_visible" value="1" <?= !empty($user['phone_visible']) ? 'checked' : '' ?>> Показывать телефон в объявлениях</label>
            </div>
            <div class="form-group">
                <label class="form-label">Город</label>
                <input type="text" name="city" class="form-input" value="<?= htmlspecialchars($user['city'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Telegram</label>
                <input type="text" name="telegram" class="form-input" value="<?= htmlspecialchars($user['telegram'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">WhatsApp</label>
                <input type="text" name="whatsapp" class="form-input" value="<?= htmlspecialchars($user['whatsapp'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Текущий пароль (обязателен для смены данных)</label>
                <input type="password" name="current_password" class="form-input" autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-label">Новый пароль (оставьте пустым, если не меняете)</label>
                <input type="password" name="new_password" class="form-input" minlength="8" autocomplete="off">
            </div>
            <div class="form-group">
                <label>Уведомления</label>
                <div>
                    <label><input type="checkbox" name="notify_email" value="1" <?= !empty($user['notify_email']) ? 'checked' : '' ?>> Email</label>
                    <label><input type="checkbox" name="notify_sms" value="1" <?= !empty($user['notify_sms']) ? 'checked' : '' ?>> SMS</label>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                <button type="button" class="btn btn-danger" id="delete-account-btn">Удалить аккаунт</button>
            </div>
        </form>

        <hr style="margin: 30px 0;">

        <h3>🔐 Двухфакторная аутентификация</h3>
        <div id="2fa-status">
            <div class="form-actions">
                <button type="button" class="btn btn-primary" id="2fa-enable-btn">Включить 2FA</button>
                <button type="button" class="btn btn-danger" id="2fa-disable-btn" style="display: none;">Отключить 2FA</button>
            </div>
            <div id="2fa-setup" style="display: none;"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
    const csrfToken = '<?= $csrfToken ?>';
    const userId = <?= $userId ?>;

    function showToast(msg, type = 'success') {
        const colors = { success: '#34C759', error: '#FF3B30', warning: '#FF9500', info: '#5A67D8' };
        Toastify({ text: msg, duration: 3000, backgroundColor: colors[type] }).showToast();
    }

    // Аватар
    document.getElementById('change-avatar-btn').addEventListener('click', () => {
        document.getElementById('avatar-file').click();
    });
    document.getElementById('avatar-file').addEventListener('change', async (e) => {
        const file = e.target.files[0];
        if (!file) return;
        const formData = new FormData();
        formData.append('action', 'avatar');
        formData.append('csrf_token', csrfToken);
        formData.append('avatar', file);
        try {
            const res = await fetch('/api/profile/manage.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                const img = document.getElementById('avatar-img');
                const placeholder = document.getElementById('avatar-placeholder');
                if (img) img.src = data.avatar_url + '?t=' + Date.now();
                if (placeholder) {
                    placeholder.style.display = 'none';
                    const newImg = document.createElement('img');
                    newImg.id = 'avatar-img';
                    newImg.src = data.avatar_url;
                    newImg.alt = 'Аватар';
                    newImg.className = 'profile-avatar';
                    document.querySelector('.profile-avatar').appendChild(newImg);
                }
                showToast('Аватар обновлён');
            } else {
                showToast(data.error || 'Ошибка', 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        }
    });

    // Обновление профиля
    document.getElementById('profile-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = {};
        for (let [key, val] of formData.entries()) data[key] = val;
        try {
            const res = await fetch('/api/profile/manage.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update', ...data })
            });
            const result = await res.json();
            if (result.success) {
                showToast(result.message || 'Профиль обновлён');
                if (result.email_pending) showToast('Письмо для подтверждения email отправлено', 'info');
                // Обновляем имя на странице
                if (data.name) document.querySelector('.profile-name').innerText = data.name;
            } else {
                showToast(result.error || 'Ошибка обновления', 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        }
    });

    // Удаление аккаунта
    document.getElementById('delete-account-btn').addEventListener('click', async () => {
        const password = prompt('Введите пароль для подтверждения удаления аккаунта:');
        if (!password) return;
        try {
            const res = await fetch('/api/profile/manage.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', password: password, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                showToast(data.message);
                setTimeout(() => { window.location.href = '/'; }, 3000);
            } else {
                showToast(data.error || 'Ошибка', 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        }
    });

    // 2FA
    async function enable2FA() {
        try {
            const res = await fetch('/api/profile/manage.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'enable-2fa', step: 1, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                const setupDiv = document.getElementById('2fa-setup');
                setupDiv.innerHTML = `
                    <p>Отсканируйте QR-код в приложении аутентификатора</p>
                    <img src="${data.qr_url}" style="max-width:200px; margin:10px auto;">
                    <p>Или введите код: <code>${data.secret}</code></p>
                    <div class="form-group"><label>Код из приложения</label><input type="text" id="2fa-code" class="form-input" placeholder="6 цифр"></div>
                    <button class="btn btn-primary" id="2fa-verify-btn">Подтвердить</button>
                `;
                setupDiv.style.display = 'block';
                document.getElementById('2fa-enable-btn').style.display = 'none';
                document.getElementById('2fa-verify-btn').addEventListener('click', async () => {
                    const code = document.getElementById('2fa-code').value;
                    const verifyRes = await fetch('/api/profile/manage.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'enable-2fa', step: 2, secret: data.secret, code: code, csrf_token: csrfToken })
                    });
                    const verifyData = await verifyRes.json();
                    if (verifyData.success) {
                        showToast('2FA успешно включена');
                        setupDiv.innerHTML = '<p>2FA активна. Сохраните резервные коды:</p><div class="backup-codes">' + verifyData.backup_codes.map(c => `<span class="backup-code">${c}</span>`).join('') + '</div>';
                        document.getElementById('2fa-disable-btn').style.display = 'inline-block';
                    } else {
                        showToast(verifyData.error || 'Неверный код', 'error');
                    }
                });
            } else {
                showToast(data.error || 'Ошибка', 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        }
    }
    async function disable2FA() {
        const password = prompt('Введите пароль для отключения 2FA');
        if (!password) return;
        try {
            const res = await fetch('/api/profile/manage.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'disable-2fa', password: password, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                showToast('2FA отключена');
                document.getElementById('2fa-disable-btn').style.display = 'none';
                document.getElementById('2fa-enable-btn').style.display = 'inline-block';
                document.getElementById('2fa-setup').style.display = 'none';
            } else {
                showToast(data.error || 'Ошибка', 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        }
    }
    document.getElementById('2fa-enable-btn').addEventListener('click', enable2FA);
    document.getElementById('2fa-disable-btn').addEventListener('click', disable2FA);
</script>
</body>
</html>