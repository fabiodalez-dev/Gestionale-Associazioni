{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($associazioni as $associazione)
    <sitemap>
        <loc>{{ route('pubblico.sitemap', $associazione->slug) }}</loc>
@if ($associazione->updated_at)
        <lastmod>{{ $associazione->updated_at->toAtomString() }}</lastmod>
@endif
    </sitemap>
@endforeach
</sitemapindex>
