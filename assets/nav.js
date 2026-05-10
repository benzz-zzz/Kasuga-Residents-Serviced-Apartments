/**
 * Mobile nav: body scroll lock, aria labels, close on link / scrim / Escape.
 */
(function () {
  var toggle = document.getElementById('nav-open');
  var menu = document.getElementById('nav-menu');
  var burger = document.getElementById('nav-burger');
  var scrim = document.getElementById('nav-scrim');

  if (!toggle || !menu || !burger) return;

  function setOpen(open) {
    document.body.classList.toggle('nav-is-open', open);
    burger.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    if (open) {
      var first = menu.querySelector('a[href]');
      if (first) setTimeout(function () { first.focus(); }, 100);
    }
  }

  function close() {
    if (toggle.checked) toggle.checked = false;
    setOpen(false);
  }

  menu.addEventListener('click', function (e) {
    if (e.target && e.target.closest('a[href]')) close();
  });

  if (scrim) {
    scrim.addEventListener('click', close);
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && toggle.checked) {
      e.preventDefault();
      close();
      burger.focus();
    }
  });

  toggle.addEventListener('change', function () {
    setOpen(toggle.checked);
  });
})();

/**
 * Room galleries: cycle photos with "Next" buttons.
 */
(function () {
  function setupGallery(gallerySelector, imageSelector) {
    var galleries = document.querySelectorAll(gallerySelector);
    galleries.forEach(function (gallery) {
      var images = gallery.querySelectorAll(imageSelector);
      var nextBtn = gallery.querySelector('[data-gallery-next]');
      var prevBtn = gallery.querySelector('[data-gallery-prev]');
      if (!nextBtn || !prevBtn || images.length < 2) return;

      var current = 0;

      function activate(idx) {
        images.forEach(function (img, i) {
          img.classList.toggle('is-active', i === idx);
        });
      }

      nextBtn.addEventListener('click', function () {
        current = (current + 1) % images.length;
        activate(current);
      });

      prevBtn.addEventListener('click', function () {
        current = (current - 1 + images.length) % images.length;
        activate(current);
      });
    });
  }

  setupGallery('.room-card__gallery', '.room-card__gallery-img');
  setupGallery('.book-room-gallery', '.book-room-gallery__img');
})();

/**
 * Password visibility toggles.
 */
(function () {
  var toggles = document.querySelectorAll('[data-password-toggle]');
  if (!toggles.length) return;

  toggles.forEach(function (btn) {
    var wrap = btn.closest('.password-field');
    if (!wrap) return;
    var input = wrap.querySelector('[data-password-toggle-target]');
    if (!input) return;

    function syncButton() {
      var visible = input.type === 'text';
      var srText = btn.querySelector('.visually-hidden');
      if (srText) {
        srText.textContent = visible ? 'Hide password' : 'Show password';
      }
      btn.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
      btn.setAttribute('aria-pressed', visible ? 'true' : 'false');
    }

    btn.addEventListener('click', function () {
      input.type = input.type === 'password' ? 'text' : 'password';
      syncButton();
      input.focus();
      var len = input.value.length;
      try {
        input.setSelectionRange(len, len);
      } catch (e) {
        // Ignore if the browser does not support setSelectionRange for this type.
      }
    });

    syncButton();
  });
})();
