@if (\App\Http\Middleware\SecurityResponseHeaders::isErpHost(request()))
    <meta name="robots" content="noindex, nofollow">
@endif
