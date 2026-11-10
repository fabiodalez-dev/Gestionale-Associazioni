@php
    $slug = $associazione->slug;
    $ultimoEvento = $eventi->max('updated_at');
    $pagine = [
        ['url' => route('pubblico.home.show', $slug), 'lastmod' => $ultimoEvento, 'priorita' => '1.0'],
        ['url' => route('pubblico.eventi.index', $slug), 'lastmod' => $ultimoEvento, 'priorita' => '0.9'],
        ['url' => route('pubblico.calendario', $slug), 'lastmod' => $ultimoEvento, 'priorita' => '0.5'],
        ['url' => route('pubblico.novita', $slug), 'lastmod' => $ultimoAnnuncio ? \Illuminate\Support\Carbon::parse($ultimoAnnuncio) : null, 'priorita' => '0.7'],
        ['url' => route('pubblico.iscriviti', $slug), 'lastmod' => null, 'priorita' => '0.8'],
        ['url' => route('pubblico.dona.show', $slug), 'lastmod' => null, 'priorita' => '0.5'],
        ['url' => route('pubblico.trasparenza', $slug), 'lastmod' => null, 'priorita' => '0.5'],
    ];
@endphp
{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($pagine as $pagina)
    <url>
        <loc>{{ $pagina['url'] }}</loc>
@if ($pagina['lastmod'])
        <lastmod>{{ $pagina['lastmod']->toAtomString() }}</lastmod>
@endif
        <priority>{{ $pagina['priorita'] }}</priority>
    </url>
@endforeach
@foreach ($eventi as $evento)
    <url>
        <loc>{{ route('pubblico.eventi.show', [$slug, $evento->id]) }}</loc>
@if ($evento->updated_at)
        <lastmod>{{ $evento->updated_at->toAtomString() }}</lastmod>
@endif
        <priority>0.8</priority>
    </url>
@endforeach
</urlset>
