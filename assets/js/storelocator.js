/**
 * NOVI Store Locator 1.4.0 — carte et recherche des points de vente.
 *
 * Configuration fournie par PHP dans window.noviStoreLocator (voir includes/frontend.php).
 * Aucune variable globale n'est exposée et aucun HTML n'est construit par concaténation :
 * toutes les données (magasins, communes) sont insérées avec textContent.
 */
(function () {
	'use strict';

	var CFG = window.noviStoreLocator;
	if (!CFG || typeof window.L === 'undefined') {
		return;
	}
	var L = window.L;

	var FRANCE_CENTER = [46.614985, 2.4636];
	var FOCUS_ZOOM = 14;
	var MAX_PLACES = 8;
	var MAX_STORE_SUGGESTIONS = 4;
	var HEX_COLOR = /^#[0-9a-f]{3,8}$/i;

	/* ------------------------------------------------------------------
	 * Utilitaires
	 * ---------------------------------------------------------------- */

	function normalize(text) {
		return String(text || '')
			.normalize('NFD')
			.replace(/[̀-ͯ]/g, '')
			.toLowerCase()
			.replace(/[-'’_.,/()]/g, ' ')
			.replace(/\bste\b/g, 'sainte')
			.replace(/\bst\b/g, 'saint')
			.replace(/\s+/g, ' ')
			.trim();
	}

	function isFrance(country) {
		var c = normalize(country);
		return c === '' || c === 'france' || c === 'fr';
	}

	function distanceKm(lat1, lon1, lat2, lon2) {
		var rad = Math.PI / 180;
		var dLat = (lat2 - lat1) * rad;
		var dLon = (lon2 - lon1) * rad;
		var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
			Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
		return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
	}

	function formatDistance(km) {
		if (km < 1) {
			return 'à ' + Math.max(10, Math.round(km * 100) * 10) + ' m';
		}
		return 'à ' + km.toLocaleString('fr-FR', { maximumFractionDigits: km < 10 ? 1 : 0 }) + ' km';
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null && text !== '') {
			node.textContent = text;
		}
		return node;
	}

	function debounce(fn, wait) {
		var timer;
		return function () {
			var args = arguments;
			clearTimeout(timer);
			timer = setTimeout(function () {
				fn.apply(null, args);
			}, wait);
		};
	}

	function fetchJSON(url) {
		return fetch(url, { credentials: 'same-origin' }).then(function (response) {
			if (!response.ok) {
				throw new Error('HTTP ' + response.status + ' sur ' + url);
			}
			return response.json();
		});
	}

	function rankText(text, query) {
		if (!text) {
			return -1;
		}
		if (text === query) {
			return 0;
		}
		if (text.indexOf(query) === 0) {
			return 1;
		}
		if (text.indexOf(' ' + query) !== -1) {
			return 2;
		}
		return text.indexOf(query) !== -1 ? 3 : -1;
	}

	/* ------------------------------------------------------------------
	 * Données (chargées une seule fois, partagées entre les instances)
	 * ---------------------------------------------------------------- */

	var storesPromise = null;
	var communesPromise = null;

	function loadStores() {
		if (!storesPromise) {
			storesPromise = fetchJSON(CFG.storesUrl)
				.then(function (list) {
					var stores = [];
					(Array.isArray(list) ? list : []).forEach(function (s) {
						var lat = parseFloat(s.lat);
						var lng = parseFloat(s.lng);
						if (!isFinite(lat) || !isFinite(lng)) {
							return;
						}
						stores.push({
							index: stores.length,
							name: String(s.name || ''),
							address1: String(s.address1 || ''),
							address2: String(s.address2 || ''),
							postcode: String(s.postcode || ''),
							city: String(s.city || ''),
							country: String(s.country || ''),
							phone: String(s.phone || ''),
							website: String(s.website || ''),
							icone: String(s.icone || ''),
							brand: String(s.brand || ''),
							services: Array.isArray(s.services) ? s.services.map(String).filter(Boolean) : [],
							lat: lat,
							lng: lng,
							n: normalize(s.name),
							nCity: normalize(s.city)
						});
					});
					stores.forEach(function (store) {
						store.brandLabel = detectBrand(store);
						store.serviceList = detectServices(store);
					});
					return stores;
				})
				.catch(function (error) {
					storesPromise = null;
					throw error;
				});
		}
		return storesPromise;
	}

	function loadCommunes() {
		if (!communesPromise) {
			communesPromise = fetchJSON(CFG.communesUrl)
				.then(function (rows) {
					return rows.map(function (r) {
						return { name: r[0], cp: r[1], l5: r[2], lat: r[3], lng: r[4], n: normalize(r[0]), nL5: normalize(r[2]) };
					});
				})
				.catch(function (error) {
					communesPromise = null;
					throw error;
				});
		}
		return communesPromise;
	}

	/** Villes des magasins hors de France (Belgique, Luxembourg, Suisse…), absentes de la base des communes. */
	function foreignPlaces(stores) {
		var seen = {};
		var places = [];
		stores.forEach(function (s) {
			if (isFrance(s.country) || !s.city) {
				return;
			}
			var key = s.nCity + '|' + s.postcode;
			if (seen[key]) {
				return;
			}
			seen[key] = true;
			places.push({ name: s.city, cp: s.postcode, l5: '', country: s.country, lat: s.lat, lng: s.lng, n: s.nCity, nL5: '' });
		});
		return places;
	}

	/** Recherche dans les communes, les villes étrangères et les noms de magasins. */
	function search(query, communes, stores) {
		var q = normalize(query);
		if (q.length < 2) {
			return [];
		}
		var digits = /^\d+$/.test(q);
		var q5 = digits && q.length === 4 ? '0' + q : null;
		var places = [];
		var seen = {};

		communes.concat(foreignPlaces(stores)).forEach(function (c) {
			var rank = -1;
			var viaL5 = false;
			if (digits) {
				if (c.cp === q || c.cp === q5) {
					rank = 0;
				} else if (c.cp.indexOf(q) === 0) {
					rank = 1;
				}
			} else {
				rank = rankText(c.n, q);
				if (rank < 0 && c.nL5) {
					var rankL5 = rankText(c.nL5, q);
					if (rankL5 >= 0) {
						viaL5 = true;
						rank = rankL5 + 4; // Lieu-dit : après les noms de communes.
					}
				}
			}
			if (rank < 0) {
				return;
			}
			var key = c.n + '|' + c.cp + '|' + (viaL5 ? c.nL5 : '');
			if (seen[key]) {
				return;
			}
			seen[key] = true;
			places.push({ type: 'place', place: c, rank: rank, viaL5: viaL5 });
		});

		places.sort(function (a, b) {
			return a.rank - b.rank || a.place.name.length - b.place.name.length || (a.place.cp < b.place.cp ? -1 : 1);
		});

		var storeHits = [];
		if (!digits) {
			stores.forEach(function (s) {
				var rank = rankText(s.n, q);
				if (rank >= 0) {
					storeHits.push({ type: 'store', store: s, rank: rank });
				}
			});
			storeHits.sort(function (a, b) {
				return a.rank - b.rank || a.store.name.localeCompare(b.store.name, 'fr');
			});
		}

		return places.slice(0, MAX_PLACES).concat(storeHits.slice(0, MAX_STORE_SUGGESTIONS));
	}

	/* ------------------------------------------------------------------
	 * Enseignes et services (filtres)
	 * ---------------------------------------------------------------- */

	var OTHER_BRAND = (CFG.labels && CFG.labels.otherBrand) || 'Autres';

	/** Enseigne : colonne « enseigne » du fichier, sinon début du nom comparé à la liste des réglages. */
	function detectBrand(store) {
		if (store.brand) {
			return store.brand;
		}
		var name = normalize(store.name);
		var brands = CFG.brands || [];
		for (var i = 0; i < brands.length; i++) {
			var b = normalize(brands[i]);
			if (b && (name === b || name.indexOf(b + ' ') === 0 || name.indexOf(b) === 0)) {
				return brands[i];
			}
		}
		return OTHER_BRAND;
	}

	/** Services : colonne « services » du fichier, plus « Soins en institut » pour l'icône signature. */
	function detectServices(store) {
		var list = store.services.slice();
		if (store.icone === 'signature' && CFG.labels && CFG.labels.signature && list.indexOf(CFG.labels.signature) === -1) {
			list.push(CFG.labels.signature);
		}
		return list;
	}

	function isMobile() {
		return window.matchMedia('(max-width: 899px)').matches;
	}

	/* ------------------------------------------------------------------
	 * Icônes
	 * ---------------------------------------------------------------- */

	var pinCache = {};

	function safeColor(color, fallback) {
		return HEX_COLOR.test(color || '') ? color : fallback;
	}

	function pinIcon(color) {
		if (!pinCache[color]) {
			pinCache[color] = L.divIcon({
				className: 'novi-sl-pin',
				html: '<svg viewBox="0 0 30 40" width="30" height="40" aria-hidden="true" focusable="false">' +
					'<path d="M15 1C7.3 1 1 7.1 1 14.8 1 25.3 15 39 15 39s14-13.7 14-24.2C29 7.1 22.7 1 15 1z" fill="' + color + '" stroke="#fff" stroke-width="2"/>' +
					'<circle cx="15" cy="15" r="5.5" fill="#fff"/></svg>',
				iconSize: [30, 40],
				iconAnchor: [15, 39],
				popupAnchor: [0, -34]
			});
		}
		return pinCache[color];
	}

	function storeColor(store) {
		var defaultColor = safeColor(CFG.markerColor, '#2a81cb');
		var colors = CFG.markerColors || {};
		return safeColor(colors[store.icone], defaultColor);
	}

	/* ------------------------------------------------------------------
	 * Contenu des fiches magasin (liste et popup)
	 * ---------------------------------------------------------------- */

	/** Liens d'itinéraire vers les trois applications proposées. */
	function directionsLinks(store, origin) {
		var dest = store.lat + ',' + store.lng;
		var from = origin ? origin.lat + ',' + origin.lng : '';
		return [
			{
				key: 'google',
				label: 'Google Maps',
				url: 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(dest) + (from ? '&origin=' + encodeURIComponent(from) : '')
			},
			{
				key: 'apple',
				label: 'Apple Plans',
				url: 'https://maps.apple.com/?daddr=' + encodeURIComponent(dest) + '&dirflg=d' + (from ? '&saddr=' + encodeURIComponent(from) : '')
			},
			{
				key: 'waze',
				label: 'Waze',
				url: 'https://waze.com/ul?ll=' + encodeURIComponent(dest) + '&navigate=yes'
			}
		];
	}

	/** Bouton « J'Y VAIS » qui déplie le choix de l'application d'itinéraire. */
	function directionsMenu(store, origin) {
		var details = el('details', 'novi-sl__go');
		var summary = el('summary', 'novi-sl__btn', "J'Y VAIS");
		summary.setAttribute('aria-label', "J'y vais : choisir l'application d'itinéraire vers " + store.name);
		details.appendChild(summary);

		var menu = el('div', 'novi-sl__go-menu');
		menu.setAttribute('role', 'group');
		menu.setAttribute('aria-label', 'Itinéraire avec');
		directionsLinks(store, origin).forEach(function (app) {
			var link = el('a', 'novi-sl__go-link novi-sl__go-link--' + app.key, app.label);
			link.href = app.url;
			link.target = '_blank';
			link.rel = 'noopener';
			link.setAttribute('aria-label', 'Itinéraire vers ' + store.name + ' avec ' + app.label + ' (nouvel onglet)');
			menu.appendChild(link);
		});
		details.appendChild(menu);

		// Un seul menu ouvert à la fois.
		details.addEventListener('toggle', function () {
			if (!details.open) {
				return;
			}
			Array.prototype.forEach.call(document.querySelectorAll('.novi-sl__go[open]'), function (other) {
				if (other !== details) {
					other.open = false;
				}
			});
		});
		return details;
	}

	function fillStoreDetails(container, store, distance, origin) {
		var address = el('p', 'novi-sl__address');
		[store.address1, store.address2, (store.postcode + ' ' + store.city).trim(), isFrance(store.country) ? '' : store.country]
			.filter(Boolean)
			.forEach(function (line, i) {
				if (i) {
					address.appendChild(document.createElement('br'));
				}
				address.appendChild(document.createTextNode(line));
			});
		container.appendChild(address);

		if (store.icone === 'signature' && CFG.labels && CFG.labels.signature) {
			container.appendChild(el('p', 'novi-sl__badge', CFG.labels.signature));
		}
		if (typeof distance === 'number') {
			container.appendChild(el('p', 'novi-sl__distance', formatDistance(distance)));
		}
		if (store.phone) {
			var phone = el('a', 'novi-sl__phone', store.phone);
			phone.href = 'tel:' + store.phone.replace(/[^0-9+]/g, '');
			var p = el('p', 'novi-sl__contact');
			p.appendChild(phone);
			container.appendChild(p);
		}
		if (/^https?:\/\//i.test(store.website)) {
			var site = el('a', 'novi-sl__website', 'Site web');
			site.href = store.website;
			site.target = '_blank';
			site.rel = 'noopener';
			var ps = el('p', 'novi-sl__contact');
			ps.appendChild(site);
			container.appendChild(ps);
		}

		container.appendChild(directionsMenu(store, origin));
	}

	/* ------------------------------------------------------------------
	 * Instance du store locator
	 * ---------------------------------------------------------------- */

	function StoreLocator(root) {
		this.root = root;
		this.input = root.querySelector('.novi-sl__input');
		this.listbox = root.querySelector('.novi-sl__suggestions');
		this.locateBtn = root.querySelector('.novi-sl__locate');
		this.statusEl = root.querySelector('.novi-sl__status');
		this.mapEl = root.querySelector('.novi-sl__map');
		this.panel = root.querySelector('.novi-sl__panel');
		this.emptyEl = root.querySelector('.novi-sl__empty');
		this.resultsEl = root.querySelector('.novi-sl__results');
		this.resultsCount = parseInt(root.getAttribute('data-results'), 10) || CFG.resultsCount || 4;
		this.filtersEl = root.querySelector('.novi-sl__filters');
		this.viewButtons = root.querySelectorAll('.novi-sl__view');
		this.stores = [];
		this.filters = { brands: [], services: [] };
		this.lastSearch = null;

		this.suggestions = [];
		this.activeIndex = -1;
		this.markers = [];
		this.userMarker = null;
		this.userPosition = null;
		this.userInteracted = false;
		this.searchToken = 0;

		root.style.setProperty('--novi-sl-marker', safeColor(CFG.markerColor, '#2a81cb'));

		this.initMap();
		this.bindSearch();
		this.bindLocate();
		this.bindViews();
		this.loadMarkers();
	}

	StoreLocator.prototype.setStatus = function (message, isError) {
		this.statusEl.textContent = message || '';
		this.statusEl.classList.toggle('is-error', !!isError);
	};

	/* ---------- Carte ---------- */

	StoreLocator.prototype.initMap = function () {
		var tiles = CFG.tiles || {};
		this.map = L.map(this.mapEl, { center: FRANCE_CENTER, zoom: 6 });
		L.tileLayer(tiles.url, {
			attribution: tiles.attribution,
			tileSize: tiles.tileSize || 256,
			zoomOffset: tiles.zoomOffset || 0,
			maxZoom: 19,
			crossOrigin: true
		}).addTo(this.map);

		this.cluster = L.markerClusterGroup({
			showCoverageOnHover: false,
			maxClusterRadius: 45,
			disableClusteringAtZoom: 13,
			chunkedLoading: true,
			iconCreateFunction: function (cluster) {
				var count = cluster.getChildCount();
				var size = count < 10 ? 34 : count < 50 ? 40 : 46;
				return L.divIcon({
					html: '<span>' + count + '</span>',
					className: 'novi-sl-cluster',
					iconSize: [size, size]
				});
			}
		});
		this.map.addLayer(this.cluster);

		this.bindMapGestures();

		var map = this.map;
		if (typeof window.ResizeObserver === 'function') {
			new window.ResizeObserver(function () {
				map.invalidateSize();
			}).observe(this.mapEl);
		}
	};

	/**
	 * La carte ne capture plus le défilement de la page :
	 * - ordinateur : zoom à la molette seulement avec Ctrl (⌘ sur Mac) ;
	 * - écran tactile : déplacement à deux doigts, un doigt fait défiler la page.
	 */
	StoreLocator.prototype.bindMapGestures = function () {
		var map = this.map;
		var mapEl = this.mapEl;
		var hint = el('div', 'novi-sl__map-hint');
		hint.setAttribute('aria-hidden', 'true');
		mapEl.appendChild(hint);
		var timer;
		var showHint = function (text) {
			hint.textContent = text;
			hint.classList.add('is-visible');
			clearTimeout(timer);
			timer = setTimeout(function () {
				hint.classList.remove('is-visible');
			}, 1300);
		};
		var isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

		mapEl.addEventListener('wheel', function (event) {
			if (!event.ctrlKey && !event.metaKey) {
				event.stopPropagation(); // Laisse la page défiler, Leaflet ne reçoit pas l'événement.
				showHint(isMac ? 'Utilisez ⌘ + molette pour zoomer sur la carte' : 'Utilisez Ctrl + molette pour zoomer sur la carte');
			}
		}, { capture: true, passive: true });

		if (window.matchMedia('(pointer: coarse)').matches) {
			map.dragging.disable();
			mapEl.addEventListener('touchmove', function (event) {
				if (event.touches.length === 1) {
					showHint('Utilisez deux doigts pour déplacer la carte');
				}
			}, { passive: true });
		}
	};

	/* ---------- Vue Carte / Liste (mobile) ---------- */

	StoreLocator.prototype.bindViews = function () {
		var self = this;
		this.setView('map');
		Array.prototype.forEach.call(this.viewButtons, function (button) {
			button.addEventListener('click', function () {
				self.setView(button.getAttribute('data-view'));
			});
		});
	};

	StoreLocator.prototype.setView = function (view) {
		// Une animation de zoom interrompue par le masquage de la carte bloquerait les suivantes.
		if (view !== 'map' && this.map._animatingZoom && typeof this.map._onZoomTransitionEnd === 'function') {
			this.map._onZoomTransitionEnd();
		}
		this.map.stop();
		this.root.setAttribute('data-view', view);
		Array.prototype.forEach.call(this.viewButtons, function (button) {
			button.setAttribute('aria-pressed', button.getAttribute('data-view') === view ? 'true' : 'false');
		});
		if (view === 'map') {
			this.map.invalidateSize();
			if (this.pendingBounds) {
				this.map.fitBounds(this.pendingBounds, { padding: [40, 40], maxZoom: FOCUS_ZOOM, animate: false });
				this.pendingBounds = null;
			}
		}
	};

	StoreLocator.prototype.updateListCount = function (count) {
		Array.prototype.forEach.call(this.viewButtons, function (button) {
			if (button.getAttribute('data-view') === 'list') {
				button.textContent = count ? 'Liste (' + count + ')' : 'Liste';
			}
		});
	};

	/* ---------- Filtres par enseigne et service ---------- */

	StoreLocator.prototype.matches = function (store) {
		var f = this.filters;
		if (f.brands.length && f.brands.indexOf(store.brandLabel) === -1) {
			return false;
		}
		for (var i = 0; i < f.services.length; i++) {
			if (store.serviceList.indexOf(f.services[i]) === -1) {
				return false;
			}
		}
		return true;
	};

	StoreLocator.prototype.renderFilters = function () {
		var self = this;
		if (!this.filtersEl || CFG.filters === false) {
			return;
		}
		var brandCounts = {};
		var serviceCounts = {};
		this.stores.forEach(function (s) {
			brandCounts[s.brandLabel] = (brandCounts[s.brandLabel] || 0) + 1;
			s.serviceList.forEach(function (sv) {
				serviceCounts[sv] = (serviceCounts[sv] || 0) + 1;
			});
		});
		var brands = Object.keys(brandCounts).sort(function (a, b) {
			if (a === OTHER_BRAND) {
				return 1;
			}
			if (b === OTHER_BRAND) {
				return -1;
			}
			return brandCounts[b] - brandCounts[a];
		});
		var services = Object.keys(serviceCounts).sort();
		if (brands.length < 2 && !services.length) {
			return; // Rien à filtrer.
		}

		var chip = function (kind, value, label, count) {
			var button = el('button', 'novi-sl__chip novi-sl__chip--' + kind);
			button.type = 'button';
			button.setAttribute('data-kind', kind);
			button.setAttribute('data-value', value);
			button.setAttribute('aria-pressed', 'false');
			button.appendChild(document.createTextNode(label));
			if (count) {
				button.appendChild(el('span', 'novi-sl__chip-count', String(count)));
			}
			return button;
		};

		this.filtersEl.textContent = '';
		this.filtersEl.appendChild(chip('all', '', 'Tous', this.stores.length));
		if (brands.length > 1) {
			brands.forEach(function (b) {
				self.filtersEl.appendChild(chip('brand', b, b, brandCounts[b]));
			});
		}
		services.forEach(function (sv) {
			self.filtersEl.appendChild(chip('service', sv, sv, serviceCounts[sv]));
		});
		this.filtersEl.hidden = false;
		this.updateChips();

		this.filtersEl.addEventListener('click', function (event) {
			var button = event.target.closest('.novi-sl__chip');
			if (!button) {
				return;
			}
			var kind = button.getAttribute('data-kind');
			var value = button.getAttribute('data-value');
			if (kind === 'all') {
				self.filters = { brands: [], services: [] };
			} else {
				var list = self.filters[kind === 'brand' ? 'brands' : 'services'];
				var pos = list.indexOf(value);
				if (pos === -1) {
					list.push(value);
				} else {
					list.splice(pos, 1);
				}
			}
			self.updateChips();
			self.applyFilters();
		});
	};

	StoreLocator.prototype.updateChips = function () {
		var f = this.filters;
		var none = !f.brands.length && !f.services.length;
		Array.prototype.forEach.call(this.filtersEl.querySelectorAll('.novi-sl__chip'), function (button) {
			var kind = button.getAttribute('data-kind');
			var value = button.getAttribute('data-value');
			var on = kind === 'all' ? none : (kind === 'brand' ? f.brands : f.services).indexOf(value) !== -1;
			button.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
	};

	StoreLocator.prototype.applyFilters = function () {
		var self = this;
		this.map.closePopup();
		var visible = this.markers.filter(function (marker, i) {
			return self.matches(self.stores[i]);
		});
		this.cluster.clearLayers();
		this.cluster.addLayers(visible);

		if (this.lastSearch) {
			this.showNearest(this.lastSearch.lat, this.lastSearch.lng, this.lastSearch.options, true);
		} else if (!visible.length) {
			this.setStatus('Aucun point de vente ne correspond à ces filtres.', true);
		} else {
			this.setStatus(visible.length === this.markers.length ? '' : visible.length + ' points de vente correspondent à ces filtres.');
		}
	};

	StoreLocator.prototype.loadMarkers = function () {
		var self = this;
		loadStores()
			.then(function (stores) {
				var markers = stores.map(function (store) {
					var marker = L.marker([store.lat, store.lng], {
						icon: pinIcon(storeColor(store)),
						title: store.name,
						alt: store.name,
						riseOnHover: true
					});
					marker.bindPopup(function () {
						return self.popupContent(store);
					}, { maxWidth: 260 });
					marker.on('click', function () {
						self.highlightCard(store.index, true);
					});
					return marker;
				});
				self.stores = stores;
				self.markers = markers;
				self.cluster.addLayers(markers);
				self.renderFilters();
			})
			.catch(function (error) {
				self.setStatus('Impossible de charger la liste des points de vente. Veuillez réessayer plus tard.', true);
				if (window.console) {
					window.console.error('[NOVI Store Locator]', error);
				}
			});
	};

	StoreLocator.prototype.popupContent = function (store) {
		var box = el('div', 'novi-sl-popup');
		box.appendChild(el('p', 'novi-sl-popup__title', store.name));
		var distance = this.userPosition ? distanceKm(this.userPosition.lat, this.userPosition.lng, store.lat, store.lng) : null;
		fillStoreDetails(box, store, distance, this.userPosition);
		return box;
	};

	StoreLocator.prototype.focusStore = function (store) {
		var marker = this.markers[store.index];
		if (!marker) {
			return;
		}
		if (isMobile() && this.root.getAttribute('data-view') !== 'map') {
			this.pendingBounds = null;
			this.setView('map');
		}
		var map = this.map;
		var cluster = this.cluster;
		var latlng = marker.getLatLng();
		var open = function () {
			cluster.zoomToShowLayer(marker, function () {
				marker.openPopup();
			});
		};
		this.highlightCard(store.index, false);
		if (map.getZoom() >= FOCUS_ZOOM && map.getBounds().contains(latlng)) {
			open();
		} else {
			map.once('moveend', function () {
				setTimeout(open, 0);
			});
			map.setView(latlng, Math.max(map.getZoom(), FOCUS_ZOOM));
		}
	};

	/* ---------- Résultats ---------- */

	StoreLocator.prototype.showNearest = function (lat, lng, options, fromFilter) {
		var self = this;
		options = options || {};
		this.lastSearch = { lat: lat, lng: lng, options: options };
		return loadStores().then(function (stores) {
			if (!stores.length) {
				self.setStatus('Aucun point de vente n\'est disponible pour le moment.', true);
				return;
			}
			var pool = stores.filter(function (s) {
				return self.matches(s);
			});
			if (!pool.length) {
				self.renderResults([]);
				self.updateListCount(0);
				self.setStatus('Aucun point de vente ne correspond à ces filtres.', true);
				return;
			}
			var ranked = pool
				.map(function (s) {
					return { store: s, distance: distanceKm(lat, lng, s.lat, s.lng) };
				})
				.sort(function (a, b) {
					return a.distance - b.distance;
				});

			var list = ranked.slice(0, self.resultsCount);
			// Tous les magasins de la commune recherchée, même au-delà du nombre demandé.
			if (options.postcode) {
				ranked.slice(self.resultsCount).forEach(function (r) {
					if (r.store.postcode === options.postcode) {
						list.push(r);
					}
				});
			}

			self.renderResults(list);
			self.updateListCount(list.length);
			if (isMobile() && !fromFilter) {
				self.setView(options.focusStore ? 'map' : 'list');
			}

			var bounds = L.latLngBounds([[lat, lng]]);
			list.forEach(function (r) {
				bounds.extend([r.store.lat, r.store.lng]);
			});
			self.pendingBounds = null;
			if (!options.focusStore) {
				if (self.root.getAttribute('data-view') === 'list' && isMobile()) {
					self.pendingBounds = bounds; // Carte masquée : cadrage au retour sur la vue Carte.
				} else {
					self.map.fitBounds(bounds, { padding: [40, 40], maxZoom: FOCUS_ZOOM });
				}
			}

			var count = list.length;
			self.setStatus(
				(count > 1 ? 'Les ' + count + ' points de vente les plus proches de ' : 'Le point de vente le plus proche de ') +
				options.label + ' (' + formatDistance(list[0].distance).replace('à ', 'le premier à ') + ').'
			);
		}).catch(function () {
			self.setStatus('Impossible de charger la liste des points de vente. Veuillez réessayer plus tard.', true);
		});
	};

	StoreLocator.prototype.renderResults = function (list) {
		var self = this;
		this.resultsEl.textContent = '';
		this.emptyEl.hidden = list.length > 0;

		list.forEach(function (r) {
			var store = r.store;
			var card = el('li', 'novi-sl__card');
			card.setAttribute('data-index', String(store.index));

			var title = el('button', 'novi-sl__card-title', store.name);
			title.type = 'button';
			title.setAttribute('aria-label', store.name + ' — afficher sur la carte');
			card.appendChild(title);
			fillStoreDetails(card, store, r.distance, self.userPosition);

			card.addEventListener('click', function (event) {
				if (event.target.closest('a, details')) {
					return; // Téléphone, site, menu d'itinéraire : comportement normal.
				}
				self.focusStore(store);
			});
			self.resultsEl.appendChild(card);
		});
		this.panel.scrollTop = 0;
	};

	StoreLocator.prototype.highlightCard = function (index, scroll) {
		var cards = this.resultsEl.querySelectorAll('.novi-sl__card');
		var target = null;
		Array.prototype.forEach.call(cards, function (card) {
			var match = card.getAttribute('data-index') === String(index);
			card.classList.toggle('is-active', match);
			if (match) {
				target = card;
			}
		});
		// Défilement interne du panneau uniquement (pas de saut de page sur mobile).
		if (target && scroll && this.panel.scrollHeight > this.panel.clientHeight + 1) {
			this.panel.scrollTo({ top: target.offsetTop - this.panel.offsetTop - 8, behavior: 'smooth' });
		}
	};

	/* ---------- Recherche (combobox accessible) ---------- */

	StoreLocator.prototype.bindSearch = function () {
		var self = this;
		var input = this.input;

		var prefetch = function () {
			loadCommunes().catch(function () {});
		};
		input.addEventListener('focus', prefetch);
		input.addEventListener('pointerenter', prefetch);

		input.addEventListener('input', function () {
			self.userInteracted = true;
		});
		input.addEventListener('input', debounce(function () {
			self.updateSuggestions();
		}, 200));

		input.addEventListener('keydown', function (event) {
			var open = !self.listbox.hidden;
			switch (event.key) {
				case 'ArrowDown':
					event.preventDefault();
					if (!open) {
						self.updateSuggestions();
					} else {
						self.setActive(self.activeIndex + 1);
					}
					break;
				case 'ArrowUp':
					if (open) {
						event.preventDefault();
						self.setActive(self.activeIndex - 1);
					}
					break;
				case 'Enter':
					event.preventDefault();
					if (open && self.suggestions.length) {
						self.choose(self.suggestions[Math.max(0, self.activeIndex)]);
					} else {
						self.updateSuggestions(true);
					}
					break;
				case 'Escape':
					if (open) {
						event.preventDefault();
						self.closeSuggestions();
					}
					break;
				case 'Tab':
					self.closeSuggestions();
					break;
			}
		});

		input.addEventListener('blur', function () {
			setTimeout(function () {
				self.closeSuggestions();
			}, 150);
		});

		// Empêche la perte de focus du champ au clic dans la liste.
		this.listbox.addEventListener('mousedown', function (event) {
			event.preventDefault();
		});
		this.listbox.addEventListener('click', function (event) {
			var option = event.target.closest('[data-i]');
			if (option) {
				self.choose(self.suggestions[parseInt(option.getAttribute('data-i'), 10)]);
			}
		});

		document.addEventListener('click', function (event) {
			if (!self.root.contains(event.target)) {
				self.closeSuggestions();
			}
		});
	};

	StoreLocator.prototype.updateSuggestions = function (chooseFirst) {
		var self = this;
		var query = this.input.value;
		var token = ++this.searchToken;

		if (normalize(query).length < 2) {
			this.closeSuggestions();
			return;
		}

		var pending = setTimeout(function () {
			self.renderMessage('Recherche en cours…');
		}, 150);

		Promise.all([loadCommunes(), loadStores()])
			.then(function (data) {
				clearTimeout(pending);
				if (token !== self.searchToken) {
					return; // Une saisie plus récente a pris le relais.
				}
				self.suggestions = search(query, data[0], data[1]);
				if (chooseFirst && self.suggestions.length) {
					self.choose(self.suggestions[0]);
					return;
				}
				self.renderSuggestions();
			})
			.catch(function () {
				clearTimeout(pending);
				self.renderMessage('La recherche est momentanément indisponible.');
			});
	};

	StoreLocator.prototype.renderMessage = function (message) {
		this.listbox.textContent = '';
		var li = el('li', 'novi-sl__suggestion novi-sl__suggestion--message', message);
		li.setAttribute('role', 'option');
		li.setAttribute('aria-disabled', 'true');
		this.listbox.appendChild(li);
		this.openSuggestions();
	};

	StoreLocator.prototype.renderSuggestions = function () {
		var self = this;
		var id = this.listbox.id;
		this.activeIndex = -1;
		this.input.removeAttribute('aria-activedescendant');

		if (!this.suggestions.length) {
			this.renderMessage('Aucun résultat. Essayez un code postal ou une ville voisine.');
			return;
		}

		this.listbox.textContent = '';
		this.suggestions.forEach(function (s, i) {
			var li = el('li', 'novi-sl__suggestion novi-sl__suggestion--' + s.type);
			li.id = id + '-opt-' + i;
			li.setAttribute('role', 'option');
			li.setAttribute('aria-selected', 'false');
			li.setAttribute('data-i', String(i));
			if (s.type === 'place') {
				var p = s.place;
				var label = p.name + (s.viaL5 && p.l5 ? ' (' + p.l5 + ')' : '');
				li.appendChild(el('span', 'novi-sl__suggestion-main', label));
				li.appendChild(el('span', 'novi-sl__suggestion-meta', p.cp + (p.country ? ' · ' + p.country : '')));
			} else {
				li.appendChild(el('span', 'novi-sl__suggestion-main', s.store.name));
				li.appendChild(el('span', 'novi-sl__suggestion-meta', 'Magasin · ' + s.store.city));
			}
			self.listbox.appendChild(li);
		});
		this.openSuggestions();
	};

	StoreLocator.prototype.setActive = function (index) {
		var options = this.listbox.querySelectorAll('[data-i]');
		if (!options.length) {
			return;
		}
		index = (index + options.length) % options.length;
		this.activeIndex = index;
		Array.prototype.forEach.call(options, function (option, i) {
			option.setAttribute('aria-selected', i === index ? 'true' : 'false');
			option.classList.toggle('is-active', i === index);
		});
		this.input.setAttribute('aria-activedescendant', options[index].id);
		options[index].scrollIntoView({ block: 'nearest' });
	};

	StoreLocator.prototype.openSuggestions = function () {
		this.listbox.hidden = false;
		this.input.setAttribute('aria-expanded', 'true');
	};

	StoreLocator.prototype.closeSuggestions = function () {
		this.listbox.hidden = true;
		this.input.setAttribute('aria-expanded', 'false');
		this.input.removeAttribute('aria-activedescendant');
		this.activeIndex = -1;
	};

	StoreLocator.prototype.choose = function (suggestion) {
		if (!suggestion) {
			return;
		}
		this.closeSuggestions();
		this.userInteracted = true;
		if (suggestion.type === 'place') {
			var p = suggestion.place;
			var label = p.name + (suggestion.viaL5 && p.l5 ? ' (' + p.l5 + ')' : '');
			this.input.value = label + ' ' + p.cp;
			this.showNearest(p.lat, p.lng, { label: label, postcode: p.cp });
		} else {
			var store = suggestion.store;
			var self = this;
			this.input.value = store.name;
			this.showNearest(store.lat, store.lng, { label: store.name, focusStore: true }).then(function () {
				self.focusStore(store);
			});
		}
	};

	/* ---------- Géolocalisation (sur demande) ---------- */

	StoreLocator.prototype.bindLocate = function () {
		var self = this;
		if (!('geolocation' in navigator)) {
			this.locateBtn.hidden = true;
			return;
		}
		this.locateBtn.addEventListener('click', function () {
			self.userInteracted = true;
			self.locate(true);
		});
		// Si le visiteur a déjà autorisé la géolocalisation sur ce site, on l'utilise sans redemander.
		if (navigator.permissions && navigator.permissions.query) {
			navigator.permissions.query({ name: 'geolocation' }).then(function (permission) {
				if (permission.state === 'granted') {
					self.locate(false);
				}
			}).catch(function () {});
		}
	};

	StoreLocator.prototype.locate = function (explicit) {
		var self = this;
		var btn = this.locateBtn;
		btn.disabled = true;
		btn.setAttribute('aria-busy', 'true');
		if (explicit) {
			this.setStatus('Localisation en cours…');
		}

		navigator.geolocation.getCurrentPosition(
			function (position) {
				btn.disabled = false;
				btn.removeAttribute('aria-busy');
				if (!explicit && self.userInteracted) {
					return; // Le visiteur a déjà lancé sa propre recherche.
				}
				var lat = position.coords.latitude;
				var lng = position.coords.longitude;
				self.userPosition = { lat: lat, lng: lng };

				if (!self.userMarker) {
					self.userMarker = L.marker([lat, lng], {
						icon: L.icon({ iconUrl: CFG.userIconUrl, iconSize: [38, 38], iconAnchor: [19, 38], popupAnchor: [0, -38] }),
						title: 'Votre position',
						alt: 'Votre position',
						zIndexOffset: 1000
					}).bindPopup('Vous êtes ici !').addTo(self.map);
				} else {
					self.userMarker.setLatLng([lat, lng]);
				}
				self.input.value = '';
				self.showNearest(lat, lng, { label: 'votre position' });
			},
			function (error) {
				btn.disabled = false;
				btn.removeAttribute('aria-busy');
				if (!explicit) {
					return;
				}
				var message = error && error.code === 1
					? 'La géolocalisation a été refusée. Saisissez votre ville ou votre code postal ci-dessus.'
					: 'Votre position n\'a pas pu être déterminée. Saisissez votre ville ou votre code postal ci-dessus.';
				self.setStatus(message, true);
			},
			{ enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }
		);
	};

	/* ------------------------------------------------------------------
	 * Démarrage
	 * ---------------------------------------------------------------- */

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') {
			Array.prototype.forEach.call(document.querySelectorAll('.novi-sl__go[open]'), function (d) {
				d.open = false;
				d.querySelector('summary').focus();
			});
		}
	});

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('.novi-sl'), function (root) {
			if (root.getAttribute('data-ready')) {
				return;
			}
			root.setAttribute('data-ready', '1');
			try {
				new StoreLocator(root);
			} catch (error) {
				if (window.console) {
					window.console.error('[NOVI Store Locator]', error);
				}
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
