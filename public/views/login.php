<!DOCTYPE html>
<html lang="pl">
<head>
    <?php include __DIR__ . '/../partials/head.html' ?>
</head>
<body>
    <?php include __DIR__ . '/../partials/nav.html' ?>
    <div class="content">
    <h1><?= $title ?></h1>
    <form method="POST" action="/login">
        <label for="email">Email:</label><br>
        <input type="email" id="email" name="email" required><br><br>

        <label for="password">Hasło:</label><br>
        <input type="password" id="password" name="password" required><br><br>

        <button type="submit">Zaloguj się</button>
    </form>
    </div>
    <script src="/public/scripts/main.js"></script>
</body>
</html>
