<?php $title = $title ?? 'WDPAI - Dashboard'; ?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <?php include __DIR__ . '/../partials/head.html' ?>
</head>
<body>
    <?php include __DIR__ . '/../partials/nav.php' ?>
    <div class="content">
        <?php if (!empty($currentUser)): ?>
            <div class="user-bar">
                <span>
                    Zalogowano jako:
                    <strong><?= htmlspecialchars((string)($currentUser['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                    (<?= htmlspecialchars((string)($currentUser['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)
                </span>
                <a href="/logout" class="logout-link">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> Wyloguj
                </a>
            </div>
        <?php endif; ?>

        <?php if (!empty($flash)): ?>
            <div class="alert alert-info">
                <i class="fa-solid fa-circle-info"></i>
                <span><?= htmlspecialchars((string)$flash, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <header class="page-header">
            <div>
                <h1><i class="fa-solid fa-users"></i> Użytkownicy</h1>
                <p class="page-subtitle">Lista wszystkich kont w systemie.</p>
            </div>
            <span class="badge">
                <?= count($users ?? []) ?> <?= count($users ?? []) === 1 ? 'użytkownik' : 'użytkowników' ?>
            </span>
        </header>

        <?php if (empty($users)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-user-slash"></i>
                <p>Brak użytkowników. <a href="/register">Zarejestruj się</a>, aby dodać pierwszego.</p>
            </div>
        <?php else: ?>
            <div class="user-grid">
                <?php foreach ($users as $user): ?>
                    <article class="user-card">
                        <div class="user-avatar">
                            <?= htmlspecialchars(strtoupper(substr((string)($user['username'] ?? '?'), 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="user-info">
                            <h3><?= htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                            <p class="user-email">
                                <i class="fa-solid fa-envelope"></i>
                                <?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <p class="user-meta">
                                <i class="fa-solid fa-calendar"></i>
                                <?= htmlspecialchars(date('Y-m-d', strtotime((string)($user['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                        <span class="status-pill <?= !empty($user['is_active']) ? 'status-active' : 'status-inactive' ?>">
                            <?= !empty($user['is_active']) ? 'aktywny' : 'nieaktywny' ?>
                        </span>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <script src="/public/scripts/main.js"></script>
</body>
</html>
