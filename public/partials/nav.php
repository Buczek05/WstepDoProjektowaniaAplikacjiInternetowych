<nav class="navbar">
    <div class="navbar-brand">
        <i class="fa-solid fa-globe"></i> WDPAI
    </div>

    <button class="navbar-toggle" aria-label="Menu">
        <i class="fa-solid fa-bars"></i>
    </button>

    <div class="navbar-links">
        <a href="/dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
        <?php if (class_exists('Session') && Session::isLoggedIn()): ?>
            <a href="/logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Wyloguj</a>
        <?php else: ?>
            <a href="/login"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
            <a href="/register"><i class="fa-solid fa-user-plus"></i> Register</a>
        <?php endif; ?>
    </div>

</nav>

<div class="sidebar-actions">
    <a href="#" title="Powiadomienia"><i class="fa-solid fa-bell"></i></a>
    <a href="#" title="Ustawienia"><i class="fa-solid fa-gear"></i></a>
    <a href="#" title="Profil"><i class="fa-solid fa-circle-user"></i></a>
</div>
