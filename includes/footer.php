</main>
<footer class="site-footer" role="contentinfo">
    <div class="container site-footer__grid">
        <div>
            <h4>Kasuga Residences</h4>
            <p>Thoughtfully furnished apartments for extended stays—clear rates, simple booking, and a resident portal that keeps your lease and visits organized.</p>
        </div>
        <div>
            <h4>Explore</h4>
            <a href="/Apartment%20system/rooms.php">View rooms</a>
            <a href="/Apartment%20system/services.php">Services</a>
            <a href="/Apartment%20system/contact.php">Contact</a>
        </div>
        <div>
            <h4>Resident</h4>
            <a href="/Apartment%20system/my_bookings.php">My reservations</a>
            <?php if (!current_user()): ?>
            <a href="/Apartment%20system/login.php">Account sign in</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="container site-footer__bottom">
        <small>&copy; <?= (int)date('Y') ?> <?= h(APP_NAME) ?> · Professional apartment management</small>
    </div>
</footer>
<script src="/Apartment%20system/assets/nav.js" defer></script>
<?php
if (isset($page_scripts)) {
    if (is_callable($page_scripts)) {
        echo (string) $page_scripts();
    } elseif (is_string($page_scripts) && $page_scripts !== '') {
        echo $page_scripts;
    }
}
?>
</body>
</html>
