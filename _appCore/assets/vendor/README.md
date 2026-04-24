# Frontend vendor libraries

appCore doesn't bundle frontend vendor code because it has no build step
and the scaffold should stay small. Drop the following raw distributions
into this directory before running the app:

```
/assets/vendor/
    bootstrap/
        css/bootstrap.min.css
        js/bootstrap.bundle.min.js
    bootstrap-icons/
        bootstrap-icons.min.css
        fonts/...
    htmx/
        htmx.min.js
    alpine/
        alpine.min.js
```

Minimum tested versions:

- Bootstrap 5.3.x
- Bootstrap Icons 1.11.x
- HTMX 2.0.x
- Alpine.js 3.13.x

Fetch the distribution zip or CDN file for each library and extract the
directories shown above. appCore reads these via `{{ asset('vendor/...') }}`,
which appends a `?v={filemtime}` cache-buster automatically.
