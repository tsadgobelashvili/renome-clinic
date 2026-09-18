# RenoMe static asset delivery — manual production change

## Status

Prepared only. The production server block is not in this repository and SSH access
has not been supplied. This is a proposed insertion, **not a verified diff against
the deployed configuration**. No server files have been changed and Nginx has not
been reloaded.

Before applying, inspect the enabled HTTPS server block for `erp.renome.ge`, its
included files, existing gzip directives, static locations and cache headers:

```bash
sudo nginx -T
```

Review this output on the server; do not publish secrets that may exist in other
server blocks. Confirm the existing document root is `/var/www/renome-clinic/public`.

## Proposed minimal insertion

Merge these directives into the existing HTTPS `server` block. If a directive is
already present at that level, change it rather than adding a duplicate. Keep the
existing root, TLS, authentication, PHP/FastCGI and Laravel routing unchanged.

```diff
 server {
     # Existing erp.renome.ge HTTPS configuration stays here.
+    gzip on;
+    gzip_vary on;
+    gzip_min_length 1024;
+    gzip_comp_level 4;
+    gzip_types text/css application/javascript text/javascript application/json
+               image/svg+xml text/plain application/xml;
+
+    # Vite's generated name-hash files only; not /build/manifest.json.
+    location ~ "^/build/assets/[^/]+-[A-Za-z0-9_-]{8}\.(?:css|js|woff2?|ttf|otf|svg|png|jpe?g|webp|avif|gif|ico)$" {
+        try_files $uri =404;
+        expires 365d;
+    }
     # Existing locations stay here.
 }
```

Place the fingerprinted-assets regex before a broader regex static-assets location.
An existing `location ^~ /build/` can prevent this regex from running: merge the
fingerprint-specific handling into that existing layout after inspection instead
of stacking competing locations. Verify the project's generated filenames against
`public/build/manifest.json`; current Vite hashes are eight characters.

`expires 365d` supplies the long-lived Cache-Control/Expires headers without adding
a location-level `add_header` that would suppress inherited security headers on
Nginx 1.24. Missing assets return 404 and never fall through to Laravel. Do not add
`expires` or a long-lived `Cache-Control` at the server level.

Gzip changes response encoding, not cache policy. Login HTML and dynamic Livewire
responses retain their existing cache headers. The Livewire script can compress
through the existing PHP routing; no new Livewire location or cache rule is added.
Non-fingerprinted assets retain their existing cache policy.

## Manual validation and reload

After reviewing and editing the actual server configuration:

```bash
sudo nginx -t
```

Only if validation succeeds, manually run:

```bash
sudo systemctl reload nginx
```

Then verify a **GET**, not only a HEAD request (use the deployed manifest's current
CSS filename if it changes):

```bash
curl -sS -H 'Accept-Encoding: gzip' -D - -o /dev/null https://erp.renome.ge/build/assets/theme-BE7cH8zh.css
curl -sS -D - -o /dev/null https://erp.renome.ge/login
curl -sS -D - -o /dev/null https://erp.renome.ge/build/assets/missing-12345678.css
```

Expect CSS `Content-Encoding: gzip`, `Vary: Accept-Encoding`, and
`Cache-Control: max-age=31536000`. Login must retain its private/no-store policy;
the missing asset must return 404 without a year-long cache header. In browser
Network tools, verify dynamic Livewire updates have no newly added long-lived
cache policy and the Livewire JavaScript response compresses.

References: [Nginx gzip module](https://nginx.org/en/docs/http/ngx_http_gzip_module.html)
and [Nginx response headers module](https://nginx.org/en/docs/http/ngx_http_headers_module.html).
