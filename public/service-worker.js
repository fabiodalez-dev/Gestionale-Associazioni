/**
 * Service Worker - Gestione Associazioni PWA (Laravel)
 * Strategie: cache-first per i file statici in /assets, rete per tutto il resto.
 * Le pagine NON vengono mai salvate in cache (contengono dati personali e
 * resterebbero sul dispositivo dopo il logout): senza rete si mostra offline.html.
 *
 * Path adattati alla webroot Laravel (public/): URL assoluti dalla root.
 * Bump CACHE_VERSION ad ogni release degli asset.
 */
'use strict';

var CACHE_VERSION = 'v2';
var STATIC_CACHE = 'static-' + CACHE_VERSION;

var PRECACHE_URLS = [
    '/offline.html',
    '/assets/vendor/bootstrap/css/bootstrap.min.css',
    '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
    '/assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css',
    '/assets/css/style.css',
    '/assets/css/design-system.css',
    '/assets/fonts/PublicSansVariable.woff2',
    '/assets/js/main.js',
    '/assets/icons/icon.svg',
    '/assets/icons/icon-192.png',
    '/assets/icons/icon-512.png',
    '/manifest.json'
];

// Install: pre-cache static assets
self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(function(cache) {
            return cache.addAll(PRECACHE_URLS);
        }).then(function() {
            return self.skipWaiting();
        })
    );
});

// Activate: clean old caches
self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(cacheNames) {
            return Promise.all(
                cacheNames.filter(function(name) {
                    return name !== STATIC_CACHE;
                }).map(function(name) {
                    return caches.delete(name);
                })
            );
        }).then(function() {
            return self.clients.claim();
        })
    );
});

// Fetch handler
self.addEventListener('fetch', function(event) {
    var request = event.request;
    var url = new URL(request.url);

    // Skip non-GET requests
    if (request.method !== 'GET') return;

    // Skip cross-origin requests (CDN resources handled by browser cache)
    if (url.origin !== self.location.origin) return;

    // Network-only for API endpoints (dati sempre freschi: check-in, verify, ...)
    if (url.pathname.indexOf('/api/') !== -1) return;

    // Navigazione: sempre dalla rete, offline.html se non c'è connessione (nessuna pagina in cache)
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(function() {
                return caches.match('/offline.html');
            })
        );
        return;
    }

    // Static assets: cache-first, update in background
    if (isStaticAsset(url.pathname)) {
        event.respondWith(
            caches.match(request).then(function(cached) {
                var fetchPromise = fetch(request).then(function(response) {
                    if (response.ok) {
                        var responseClone = response.clone();
                        caches.open(STATIC_CACHE).then(function(cache) {
                            cache.put(request, responseClone);
                        });
                    }
                    return response;
                }).catch(function() {
                    return cached;
                });

                return cached || fetchPromise;
            })
        );
        return;
    }
});

function isStaticAsset(pathname) {
    // Solo i file pubblicati dell'app: niente foto, documenti o risposte JSON degli utenti
    return pathname.indexOf('/assets/') === 0 || pathname === '/manifest.json' || pathname === '/offline.html';
}
