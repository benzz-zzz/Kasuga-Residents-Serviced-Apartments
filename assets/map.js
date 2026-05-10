(() => {
  function initOne(el) {
    if (!el || el.dataset.inited === "1") return;
    if (!window.L) return;

    const lat = Number(el.dataset.lat || "0");
    const lng = Number(el.dataset.lng || "0");
    const zoom = Number(el.dataset.zoom || "14");
    const title = String(el.dataset.title || "");
    const address = String(el.dataset.address || "");

    if (!Number.isFinite(lat) || !Number.isFinite(lng) || (lat === 0 && lng === 0)) return;

    const map = L.map(el, {
      zoomControl: true,
      scrollWheelZoom: false,
      dragging: true,
      tap: true,
    }).setView([lat, lng], zoom);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    const marker = L.marker([lat, lng]).addTo(map);
    const popup = [title, address].filter(Boolean).join("<br>");
    if (popup) marker.bindPopup(popup);

    el.dataset.inited = "1";
  }

  function initAll() {
    document.querySelectorAll("[data-leaflet-map]").forEach(initOne);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAll);
  } else {
    initAll();
  }
})();

