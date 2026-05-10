<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_login();

$bookingId = (int)($_GET['booking_id'] ?? 0);
$stmt = db()->prepare("
    SELECT b.*, r.title AS room_title
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    WHERE b.id = ? AND b.user_id = ? AND b.status IN ('confirmed', 'checked_out')
");
$stmt->execute([$bookingId, (int)current_user()['id']]);
$booking = $stmt->fetch();

if (!$booking) {
    $_SESSION['flash_error'] = 'Only completed stays (confirmed or checked out) can be reviewed.';
    redirect('/Apartment%20system/my_bookings.php');
}

$errors = [];
if (is_post()) {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Invalid request token.';
    } else {
        $rating = (int)($_POST['rating'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');
        if ($rating < 1 || $rating > 5) {
            $errors[] = 'Rating must be between 1 and 5.';
        }
        if ($comment !== '' && (mb_strlen($comment) < 5 || mb_strlen($comment) > 250)) {
            $errors[] = 'Comment must be 5-250 characters when provided.';
        }
        if (!$errors) {
            $insert = db()->prepare('
                INSERT INTO reviews (user_id, room_id, rating, comment, created_at)
                VALUES (?, ?, ?, ?, ?)
            ');
            $insert->execute([(int)current_user()['id'], (int)$booking['room_id'], $rating, $comment, db_timestamp()]);
            $_SESSION['flash_success'] = 'Thank you for your feedback.';
            redirect('/Apartment%20system/my_bookings.php');
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="panel panel--narrow">
    <header class="page-title">
        <p class="page-title__kicker">Feedback</p>
        <h1>Review your stay</h1>
        <p>Room: <strong><?= h($booking['room_title']) ?></strong></p>
    </header>
    <?php foreach ($errors as $error): ?><div class="alert error"><?= h($error) ?></div><?php endforeach; ?>
    <form class="form form--wide" method="post" id="review-form">
        <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">
        <input type="hidden" name="rating" id="rating-input" value="">
        <fieldset class="rating-picker">
            <legend>Your rating</legend>
            <div class="rating-picker__row" id="rating-picker" role="radiogroup" aria-label="Rate from 1 to 5 stars">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <button type="button" class="rating-picker__btn" data-value="<?= $i ?>" aria-label="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>" aria-pressed="false">★</button>
                <?php endfor; ?>
            </div>
            <span class="rating-picker__hint" id="rating-hint">Tap a star to choose 1–5.</span>
        </fieldset>
        <label for="comment">Comment (optional)</label>
        <textarea id="comment" name="comment" maxlength="250" placeholder="What stood out about your stay?"></textarea>
        <button type="submit" class="btn btn--primary btn--block">Submit review</button>
    </form>
</div>
<script>
(function () {
  var wrap = document.getElementById('rating-picker');
  var hid = document.getElementById('rating-input');
  var hint = document.getElementById('rating-hint');
  var form = document.getElementById('review-form');
  if (!wrap || !hid || !form) return;
  var btns = [].slice.call(wrap.querySelectorAll('.rating-picker__btn'));
  var selected = parseInt(hid.value, 10) || 0;
  var labels = ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'];

  function paintStars(activeCount) {
    btns.forEach(function (b) {
      var n = parseInt(b.getAttribute('data-value'), 10);
      var on = activeCount > 0 && n <= activeCount;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function updateHint(count) {
    if (!hint) return;
    if (count > 0 && labels[count]) {
      hint.textContent = labels[count] + ' (' + count + ' of 5 stars)';
    } else {
      hint.textContent = 'Tap a star to choose 1–5.';
    }
  }

  function showPreview(previewCount) {
    paintStars(previewCount);
    updateHint(previewCount);
  }

  function commitChoice(value) {
    selected = value;
    hid.value = String(value);
    paintStars(value);
    updateHint(value);
  }

  btns.forEach(function (b) {
    b.addEventListener('click', function () {
      commitChoice(parseInt(b.getAttribute('data-value'), 10));
    });
    b.addEventListener('mouseenter', function () {
      var v = parseInt(b.getAttribute('data-value'), 10);
      showPreview(v);
    });
  });
  wrap.addEventListener('mouseleave', function () {
    paintStars(selected);
    updateHint(selected);
  });

  if (selected > 0) {
    commitChoice(selected);
  }

  form.addEventListener('submit', function (e) {
    var v = parseInt(hid.value, 10);
    if (v < 1 || v > 5) {
      e.preventDefault();
      hid.setCustomValidity('Please choose a star rating from 1 to 5.');
      hid.reportValidity();
    } else {
      hid.setCustomValidity('');
    }
  });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
