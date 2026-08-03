import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import '../styles/public-route-map.css';

import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

L.Icon.Default.mergeOptions({
  iconRetinaUrl: markerIcon2x,
  iconUrl: markerIcon,
  shadowUrl: markerShadow,
});

const mapInstances = new Map();
const focusZoom = 16;

const coordinateValue = (value, min, max) => {
  if (value === null || value === undefined || value === '') {
    return null;
  }

  const number = Number.parseFloat(String(value).replace(',', '.'));

  return Number.isFinite(number) && number >= min && number <= max ? number : null;
};

const routePoints = (mapElement) => {
  let rawPoints = [];

  try {
    rawPoints = JSON.parse(mapElement.dataset.points ?? '[]');
  } catch {
    return [];
  }

  if (!Array.isArray(rawPoints)) {
    return [];
  }

  return rawPoints
    .map((point, index) => {
      const latitude = coordinateValue(point.latitude, -90, 90);
      const longitude = coordinateValue(point.longitude, -180, 180);

      if (latitude === null || longitude === null) {
        return null;
      }

      return {
        id: point.id === null || point.id === undefined ? null : String(point.id),
        latitude,
        longitude,
        position: Number.isFinite(Number.parseInt(point.position, 10))
          ? Number.parseInt(point.position, 10)
          : index + 1,
        title: String(point.title || `Étape ${point.position || index + 1}`),
        type: String(point.type || ''),
      };
    })
    .filter(Boolean)
    .sort((first, second) => first.position - second.position);
};

const escapeHtml = (value) => String(value)
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;')
  .replaceAll("'", '&#039;');

const popupContent = (point) => {
  const label = point.type ? `<span>${escapeHtml(point.type)}</span>` : '';

  return `
    <strong>${point.position}. ${escapeHtml(point.title)}</strong>
    ${label}
  `;
};

const numberedMarkerIcon = (position) => L.divIcon({
  className: 'public-route-map__marker-number',
  html: `<span aria-hidden="true">${position}</span>`,
  iconSize: [34, 34],
  iconAnchor: [17, 34],
  popupAnchor: [0, -30],
});

const initMap = (mapElement) => {
  const points = routePoints(mapElement);
  const mapId = mapElement.dataset.mapId || mapElement.closest('.public-route-map')?.id || '';

  if (points.length === 0 || mapId === '' || mapInstances.has(mapId) || mapElement.dataset.publicHikeMapReady === 'true') {
    return;
  }

  const coordinates = points.map((point) => [point.latitude, point.longitude]);
  const markers = [];
  const markersById = new Map();
  const map = L.map(mapElement, {
    scrollWheelZoom: false,
  });

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(map);

  points.forEach((point) => {
    const marker = L.marker([point.latitude, point.longitude], {
      title: `${point.position}. ${point.title}`,
      icon: numberedMarkerIcon(point.position),
    })
      .addTo(map)
      .bindPopup(popupContent(point));

    markers.push(marker);

    if (point.id !== null) {
      markersById.set(point.id, marker);
    }
  });

  if (coordinates.length > 1) {
    L.polyline(coordinates, {
      color: '#2f7b59',
      weight: 4,
      opacity: 0.82,
    }).addTo(map);

    map.fitBounds(L.latLngBounds(coordinates), {
      padding: [26, 26],
      maxZoom: 15,
    });
  } else {
    map.setView(coordinates[0], 14);
  }

  mapElement.dataset.publicHikeMapReady = 'true';
  mapInstances.set(mapId, {
    map,
    markers,
    markersById,
    container: document.getElementById(mapId) || mapElement,
  });
};

export const focusPublicHikeMapPoint = (trigger) => {
  const mapId = (trigger.getAttribute('href') || '').replace(/^#/, '');
  const instance = mapInstances.get(mapId);

  if (!instance) {
    return;
  }

  const pointId = trigger.dataset.pointId ?? null;
  const pointIndex = Number.parseInt(trigger.dataset.pointIndex ?? '', 10);
  const marker = pointId && instance.markersById.has(pointId)
    ? instance.markersById.get(pointId)
    : instance.markers[pointIndex];

  if (!marker) {
    return;
  }

  instance.container.scrollIntoView({
    behavior: 'smooth',
    block: 'center',
  });

  window.setTimeout(() => {
    instance.map.invalidateSize();
    instance.map.setView(marker.getLatLng(), focusZoom, {
      animate: true,
    });
    marker.openPopup();
  }, 180);
};

export const initPublicHikeMaps = () => {
  document.querySelectorAll('[data-public-hike-map]').forEach(initMap);
};
