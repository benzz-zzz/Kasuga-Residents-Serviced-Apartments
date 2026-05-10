        </div>
    </div>
</div>
<script>
(function() {
  var b = document.getElementById('admin-menu-btn');
  var body = document.getElementById('admin-body');
  var side = document.getElementById('admin-sidebar');
  var scr = document.getElementById('admin-scrim');
  if (!b || !body) return;
  function close() {
    body.classList.remove('admin-nav-open');
    b.setAttribute('aria-expanded', 'false');
    b.setAttribute('aria-label', 'Open menu');
    if (scr) { scr.setAttribute('aria-hidden', 'true'); }
  }
  function open() {
    body.classList.add('admin-nav-open');
    b.setAttribute('aria-expanded', 'true');
    b.setAttribute('aria-label', 'Close menu');
    if (scr) { scr.setAttribute('aria-hidden', 'false'); }
  }
  b.addEventListener('click', function() {
    if (body.classList.contains('admin-nav-open')) close(); else open();
  });
  if (scr) scr.addEventListener('click', close);
  if (side) {
    side.querySelectorAll('a').forEach(function(a) {
      a.addEventListener('click', function() {
        if (window.matchMedia('(max-width: 900px)').matches) close();
      });
    });
  }
})();

(function () {
  var root = document.getElementById('admin-notify');
  var btn = document.getElementById('admin-notify-btn');
  var badge = document.getElementById('admin-notify-badge');
  var panel = document.getElementById('admin-notify-panel');
  var list = document.getElementById('admin-notify-list');
  if (!root || !btn || !badge || !panel || !list) return;

  var csrf = root.getAttribute('data-csrf') || '';
  var isOpen = false;

  function escapeHtml(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function eventLabel(item) {
    if (item.event_type === 'payment_submitted') {
      return 'Payment submitted';
    }
    return 'New booking';
  }

  function renderItems(items) {
    if (!Array.isArray(items) || items.length === 0) {
      list.innerHTML = '<li class="admin-notify__empty">No notifications yet.</li>';
      return;
    }
    list.innerHTML = items.map(function (item) {
      var unreadClass = item.is_unread ? ' is-unread' : '';
      var when = escapeHtml(item.event_at || '');
      var bookingId = Number(item.booking_id || 0);
      var room = escapeHtml(item.room_code || '');
      var guest = escapeHtml(item.guest_name || '');
      var label = escapeHtml(eventLabel(item));
      var href = 'bookings.php';
      if (bookingId > 0) {
        href += '#booking-' + bookingId;
      }
      return (
        '<li class="admin-notify__item' + unreadClass + '">' +
          '<a href="' + href + '">' +
            '<strong>' + label + '</strong>' +
            '<span>Booking #' + bookingId + ' · ' + room + ' · ' + guest + '</span>' +
            '<small>' + when + '</small>' +
          '</a>' +
        '</li>'
      );
    }).join('');
  }

  function renderBadge(unread) {
    var count = Number(unread || 0);
    if (count <= 0) {
      badge.hidden = true;
      badge.textContent = '0';
      return;
    }
    badge.hidden = false;
    badge.textContent = String(count > 99 ? '99+' : count);
  }

  function loadNotifications() {
    fetch('notifications_poll.php', { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || data.ok !== true) return;
        renderBadge(data.unread_count);
        renderItems(data.items);
      })
      .catch(function () {});
  }

  function markSeen() {
    var body = new URLSearchParams();
    body.append('csrf', csrf);
    fetch('notifications_poll.php?action=mark_seen', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || data.ok !== true) return;
        renderBadge(data.unread_count);
        renderItems(data.items);
      })
      .catch(function () {});
  }

  function setOpen(open) {
    isOpen = open;
    panel.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      markSeen();
    }
  }

  btn.addEventListener('click', function (e) {
    e.preventDefault();
    setOpen(!isOpen);
  });

  document.addEventListener('click', function (e) {
    if (!isOpen) return;
    if (root.contains(e.target)) return;
    setOpen(false);
  });

  loadNotifications();
  setInterval(loadNotifications, 7000);
})();
</script>
</body>
</html>
