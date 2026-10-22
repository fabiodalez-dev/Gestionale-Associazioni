{{-- App installabile (PWA): manifest e registrazione del service worker.
     Il service worker salva in cache solo i file statici, mai le pagine. --}}
<link rel="manifest" href="{{ asset('manifest.json') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="{{ asset('assets/icons/icon-192.png') }}">
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('{{ asset('service-worker.js') }}').catch(function () {});
        });
    }
</script>
