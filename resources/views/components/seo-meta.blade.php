@props(['seo'])

<title>{{ $seo->title }}</title>
<meta name="description" content="{{ $seo->description }}">
<meta name="robots" content="{{ $seo->robots }}">
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
<link rel="shortcut icon" href="{{ asset('favicon.svg') }}">
<link rel="canonical" href="{{ $seo->url }}">
<meta property="og:site_name" content="{{ $seo->siteName }}">
<meta property="og:title" content="{{ $seo->title }}">
<meta property="og:description" content="{{ $seo->description }}">
<meta property="og:type" content="{{ $seo->type }}">
<meta property="og:url" content="{{ $seo->url }}">
<meta property="og:image" content="{{ $seo->image }}">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ $seo->title }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seo->title }}">
<meta name="twitter:description" content="{{ $seo->description }}">
<meta name="twitter:image" content="{{ $seo->image }}">
<meta name="twitter:image:alt" content="{{ $seo->title }}">
@php
    $structuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'WebApplication',
        'name' => $seo->siteName,
        'url' => $seo->url,
        'description' => $seo->description,
        'applicationCategory' => 'FinanceApplication',
    ];
@endphp
<script type="application/ld+json">
    {!! json_encode($structuredData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
</script>
