<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

require_once dirname(__DIR__) . '/includes/admin_validation.php';



$roomId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$room = $roomId > 0 ? db()->prepare('SELECT * FROM rooms WHERE id = ?') : null;

if ($room) {

    $room->execute([$roomId]);

    $room = $room->fetch() ?: null;

    if (!$room) {

        $_SESSION['flash_error'] = 'Room not found.';

        redirect(admin_url('rooms.php'));

    }

} else {

    $room = [

        'room_code' => '',

        'title' => '',

        'description' => '',

        'monthly_rate' => '',

        'capacity' => '2',

        'image_url' => 'https://picsum.photos/seed/newroom/600/360',

        'is_active' => 1,

        'occupancy_status' => 'vacant',

    ];

}



$imageUrlsForm = [];

if ($roomId > 0) {

    $im = db()->prepare('SELECT image_url FROM room_images WHERE room_id = ? ORDER BY sort_order ASC, id ASC');

    $im->execute([$roomId]);

    $imageUrlsForm = array_column($im->fetchAll(), 'image_url');

}

if ($imageUrlsForm === []) {

    $def = trim((string) ($room['image_url'] ?? ''));

    $imageUrlsForm = $def !== '' ? [$def] : ['https://picsum.photos/seed/newroom/600/360'];

}

while (count($imageUrlsForm) < ROOM_GALLERY_MAX_IMAGES) {

    $imageUrlsForm[] = '';

}



$errors = [];

if (is_post()) {

    if (!verify_csrf($_POST['csrf'] ?? null)) {

        $errors[] = 'Invalid security token.';

    } else {

        $imageUrls = normalize_admin_room_image_urls($_POST['image_urls'] ?? []);

        $in = [

            'room_code' => $_POST['room_code'] ?? '',

            'title' => $_POST['title'] ?? '',

            'description' => $_POST['description'] ?? '',

            'monthly_rate' => $_POST['monthly_rate'] ?? '',
            'capacity' => $_POST['capacity'] ?? '',

            'image_urls' => $imageUrls,

            'occupancy_status' => $_POST['occupancy_status'] ?? 'vacant',

        ];

        $errors = validate_admin_room($in, $roomId > 0 ? $roomId : null);

        if (empty($errors)) {

            $code = strtoupper(trim($in['room_code']));

            $occ = in_array((string) $in['occupancy_status'], room_occupancy_statuses(), true)

                ? (string) $in['occupancy_status']

                : 'vacant';

            $primary = $imageUrls[0];

            $pdo = db();

            try {

                $pdo->beginTransaction();

                if ($roomId > 0) {

                    $stmt = $pdo->prepare('

                    UPDATE rooms SET

                      room_code = ?, title = ?, description = ?, monthly_rate = ?, capacity = ?, image_url = ?,

                      is_active = ?, occupancy_status = ?

                    WHERE id = ?

                ');

                    $stmt->execute([

                        $code,

                        trim($in['title']),

                        trim($in['description']),

                        (float) $in['monthly_rate'],

                        (int) $in['capacity'],

                        $primary,

                        isset($_POST['is_active']) ? 1 : 0,

                        $occ,

                        $roomId,

                    ]);

                    room_persist_gallery($pdo, $roomId, $imageUrls);

                } else {

                    $stmt = $pdo->prepare('

                    INSERT INTO rooms (room_code, title, description, monthly_rate, capacity, image_url, is_active, occupancy_status)

                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)

                ');

                    $stmt->execute([

                        $code,

                        trim($in['title']),

                        trim($in['description']),

                        (float) $in['monthly_rate'],

                        (int) $in['capacity'],

                        $primary,

                        isset($_POST['is_active']) ? 1 : 0,

                        $occ,

                    ]);

                    $newId = (int) $pdo->lastInsertId();

                    room_persist_gallery($pdo, $newId, $imageUrls);

                }

                $pdo->commit();

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {

                    $pdo->rollBack();

                }

                $errors[] = 'Could not save the room. Please try again.';

            }

            if (empty($errors)) {

                $_SESSION['flash_success'] = $roomId > 0 ? 'Room updated.' : 'Room created.';

                redirect(admin_url('rooms.php'));

            }

        }

    }

    if (!empty($errors) && is_post()) {

        $room = [

            'room_code' => (string) ($_POST['room_code'] ?? ''),

            'title' => (string) ($_POST['title'] ?? ''),

            'description' => (string) ($_POST['description'] ?? ''),

            'monthly_rate' => (string) ($_POST['monthly_rate'] ?? ''),

            'capacity' => (string) ($_POST['capacity'] ?? '2'),

            'image_url' => (string) ($_POST['image_urls'][0] ?? ''),

            'is_active' => isset($_POST['is_active']) ? 1 : 0,

            'occupancy_status' => (string) ($_POST['occupancy_status'] ?? 'vacant'),

        ];

        $imageUrlsForm = normalize_admin_room_image_urls($_POST['image_urls'] ?? []);

        while (count($imageUrlsForm) < ROOM_GALLERY_MAX_IMAGES) {

            $imageUrlsForm[] = '';

        }

    }

}



$adminPageTitle = $roomId > 0 ? 'Edit room' : 'Add room';

$adminNav = 'rooms';

require_once __DIR__ . '/includes/header.php';

?>

<div class="admin-card admin-form" style="max-width: 720px">

    <h2><?= $roomId > 0 ? 'Edit listing' : 'New listing' ?></h2>

    <?php foreach ($errors as $e): ?><div class="admin-alert admin-alert--error"><?= h($e) ?></div><?php endforeach; ?>

    <form method="post">

        <input type="hidden" name="csrf" value="<?= h(generate_csrf()) ?>">

        <div class="row">

            <div>

                <label for="room_code">Room code</label>

                <input id="room_code" name="room_code" required value="<?= h((string) ($room['room_code'] ?? '')) ?>" maxlength="32">

            </div>

            <div>

                <label for="capacity">Capacity (guests)</label>

                <input id="capacity" name="capacity" type="number" min="1" required value="<?= h((string) ($room['capacity'] ?? '2')) ?>">

            </div>

        </div>

        <label for="title">Title</label>

        <input id="title" name="title" required maxlength="120" value="<?= h((string) ($room['title'] ?? '')) ?>">

        <label for="description">Description</label>

        <textarea id="description" name="description" rows="4" required maxlength="2000"><?= h((string) ($room['description'] ?? '')) ?></textarea>

        <label for="monthly_rate">Monthly rate (PHP)</label>

        <input id="monthly_rate" name="monthly_rate" type="number" step="0.01" min="1" required value="<?= h((string) ($room['monthly_rate'] ?? '')) ?>">



        <fieldset class="admin-fieldset">

            <legend>Photo URLs</legend>

            <p class="admin-muted admin-muted--sm admin-fieldset__intro">Add 1–<?= (int) ROOM_GALLERY_MAX_IMAGES ?> <code>https</code> image links. The first photo is the primary listing image; the public site shows up to <?= (int) ROOM_GALLERY_PUBLIC_MAX ?> thumbnails on each room card and on the booking page.</p>

            <div class="admin-upload-dropzone" id="gallery-dropzone" data-upload-url="<?= h(admin_url('upload_gallery_image.php')) ?>" tabindex="0" role="region" aria-label="Upload gallery photos">

                <input type="file" id="gallery-file-input" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>

                <p class="admin-upload-dropzone__lead">Drag and drop image files here, or <button type="button" class="admin-upload-dropzone__browse" id="gallery-browse-btn">choose from your computer</button>.</p>

                <p class="admin-muted admin-muted--xs admin-upload-dropzone__hint">JPEG, PNG, WebP, or GIF — up to 5 MB each. Each upload fills the next empty photo slot (gallery is images only, not PDFs).</p>

            </div>

            <p class="admin-upload-status" id="gallery-upload-status" role="status" aria-live="polite"></p>

            <?php foreach ($imageUrlsForm as $idx => $urlVal): ?>

                <label for="image_url_<?= (int) $idx ?>" class="admin-gallery-label">Photo <?= (int) $idx + 1 ?><?= $idx === 0 ? ' (primary)' : '' ?></label>

                <input id="image_url_<?= (int) $idx ?>" name="image_urls[]" type="url" maxlength="2000" value="<?= h((string) $urlVal) ?>" placeholder="https://…" class="admin-gallery-url">

            <?php endforeach; ?>

        </fieldset>



        <label for="occupancy_status" style="margin-top:1rem">Reservation status (public site)</label>

        <select id="occupancy_status" name="occupancy_status" required>

            <?php foreach (room_occupancy_options() as $val => $label): ?>

                <option value="<?= h($val) ?>"<?= (($room['occupancy_status'] ?? 'vacant') === $val) ? ' selected' : '' ?>><?= h($label) ?></option>

            <?php endforeach; ?>

        </select>

        <p class="admin-muted admin-muted--sm" style="margin:0.35rem 0 0">Occupied shows a <strong>Reserved</strong> badge; maintenance shows <strong>Unavailable</strong>. Both block new bookings while the listing stays on the catalog (if “On website” is on).</p>

        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;margin-top:0.85rem">

            <input type="checkbox" name="is_active" value="1" <?= !empty($room['is_active']) ? ' checked' : '' ?>>

            <span style="text-transform:none;font-size:0.9rem;letter-spacing:0">Visible on public site</span>

        </label>

        <div style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center">

            <button type="submit" class="admin-btn" style="margin-top:0.75rem">Save room</button>

            <a class="admin-btn admin-btn--ghost" style="margin-top:0.75rem" href="<?= h(admin_url('rooms.php')) ?>">Cancel</a>

        </div>

    </form>

</div>

<script>

(function () {

    var dz = document.getElementById('gallery-dropzone');

    var fileInput = document.getElementById('gallery-file-input');

    var browseBtn = document.getElementById('gallery-browse-btn');

    var statusEl = document.getElementById('gallery-upload-status');

    if (!dz || !fileInput || !statusEl) return;

    var form = dz.closest('form');

    if (!form) return;

    var uploadUrl = dz.getAttribute('data-upload-url') || '';

    function galleryInputs() {

        return [].slice.call(form.querySelectorAll('input.admin-gallery-url'));

    }

    function nextEmptySlot() {

        var ins = galleryInputs();

        for (var i = 0; i < ins.length; i++) {

            if (!ins[i].value.trim()) return ins[i];

        }

        return null;

    }

    function setStatus(msg) {

        statusEl.textContent = msg || '';

    }

    function uploadOne(file) {

        var csrf = form.querySelector('input[name="csrf"]');

        if (!csrf || !csrf.value) {

            return Promise.reject(new Error('Missing security token; reload the page.'));

        }

        var slot = nextEmptySlot();

        if (!slot) {

            return Promise.reject(new Error('All photo slots are full.'));

        }

        var fd = new FormData();

        fd.append('csrf', csrf.value);

        fd.append('file', file);

        return fetch(uploadUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {

            return r.text().then(function (text) {

                var data;

                try {

                    data = JSON.parse(text);

                } catch (e) {

                    throw new Error('Unexpected server response.');

                }

                if (!data || !data.ok || !data.url) {

                    throw new Error((data && data.error) ? data.error : 'Upload failed.');

                }

                slot.value = data.url;

                return data.url;

            });

        });

    }

    function handleFiles(fileList) {

        var files = [].slice.call(fileList || []).filter(function (f) { return f && f.type && f.type.indexOf('image/') === 0; });

        if (!files.length) {

            setStatus('No image files selected.');

            return;

        }

        setStatus('Uploading…');

        var chain = Promise.resolve();

        var ok = 0;

        var errs = [];

        files.forEach(function (file) {

            chain = chain.then(function () {

                return uploadOne(file).then(function () {

                    ok++;

                }).catch(function (e) {

                    errs.push(e.message || String(e));

                });

            });

        });

        chain.then(function () {

            if (errs.length && !ok) setStatus(errs[0]);

            else if (errs.length) setStatus('Uploaded ' + ok + ' file(s). ' + errs[0]);

            else setStatus('Uploaded ' + ok + ' file(s). Save the room to keep changes.');

        });

    }

    dz.addEventListener('dragover', function (e) {

        e.preventDefault();

        dz.classList.add('is-dragover');

    });

    dz.addEventListener('dragleave', function (e) {

        if (!dz.contains(e.relatedTarget)) dz.classList.remove('is-dragover');

    });

    dz.addEventListener('drop', function (e) {

        e.preventDefault();

        dz.classList.remove('is-dragover');

        handleFiles(e.dataTransfer && e.dataTransfer.files);

    });

    dz.addEventListener('keydown', function (e) {

        if (e.key === 'Enter' || e.key === ' ') {

            e.preventDefault();

            fileInput.click();

        }

    });

    browseBtn && browseBtn.addEventListener('click', function () { fileInput.click(); });

    fileInput.addEventListener('change', function () {

        handleFiles(fileInput.files);

        fileInput.value = '';

    });

})();

</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

