{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>{{ $associazione->nome }}</title>
        <link>{{ route('pubblico.home.show', $associazione->slug) }}</link>
        <atom:link href="{{ route('pubblico.feed', $associazione->slug) }}" rel="self" type="application/rss+xml"/>
        <description>{{ filled($associazione->descrizione_pubblica) ? \Illuminate\Support\Str::limit(strip_tags($associazione->descrizione_pubblica), 300) : 'Eventi e novità di '.$associazione->nome }}</description>
        <language>it-it</language>
@if ($voci->isNotEmpty())
        <lastBuildDate>{{ $voci->first()['data']->toRfc2822String() }}</lastBuildDate>
@endif
@foreach ($voci as $voce)
        <item>
            <title>{{ $voce['titolo'] }}</title>
            <link>{{ $voce['link'] }}</link>
            <guid isPermaLink="false">{{ $associazione->slug }}-{{ $voce['guid'] }}</guid>
            <pubDate>{{ $voce['data']->toRfc2822String() }}</pubDate>
@if (filled(trim($voce['descrizione'])))
            <description>{{ \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', $voce['descrizione'])), 500) }}</description>
@endif
        </item>
@endforeach
    </channel>
</rss>
