<?php $title = $title ?? 'WDPAI - Register'; ?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <?php include __DIR__ . '/../partials/head.html' ?>
</head>
<body>
    <?php include __DIR__ . '/../partials/nav.php' ?>
    <div class="content auth-content">
        <div class="auth-card">
            <div class="auth-card-header">
                <i class="fa-solid fa-user-plus"></i>
                <h1>Załóż konto</h1>
                <p class="auth-subtitle">Dołącz do nas w kilka sekund.</p>
            </div>

            <?php if (!empty($messages)): ?>
                <div class="alert alert-error">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <ul>
                        <?php foreach ((array)$messages as $msg): ?>
                            <li><?= htmlspecialchars((string)$msg, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="/register" class="auth-form">
                <?= $csrf ?? '' ?>
                <div class="form-group">
                    <label for="username"><i class="fa-solid fa-user"></i> Nazwa użytkownika</label>
                    <input type="text" id="username" name="username" placeholder="np. jan_kowalski" maxlength="50" required>
                </div>

                <div class="form-group">
                    <label for="email"><i class="fa-solid fa-envelope"></i> Email</label>
                    <input type="email" id="email" name="email" placeholder="nazwa@example.com" maxlength="100" required>
                </div>

                <div class="form-group">
                    <label for="password"><i class="fa-solid fa-lock"></i> Hasło</label>
                    <input type="password" id="password" name="password" placeholder="••••••••" maxlength="200" required>
                    <small class="password-hint">Min. 8 znaków</small>
                </div>

                <div class="form-group">
                    <label for="password2"><i class="fa-solid fa-lock"></i> Powtórz hasło</label>
                    <input type="password" id="password2" name="password2" placeholder="••••••••" maxlength="200" required>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-user-plus"></i> Zarejestruj się
                </button>
            </form>

            <p class="auth-footer">
                Masz już konto?
                <a href="/login">Zaloguj się</a>
            </p>
        </div>
    </div>
    <script src="/public/scripts/main.js"></script>
</body>
</html>
