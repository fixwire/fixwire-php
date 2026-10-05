# Order page (no framework)

A page of a PHP app without a framework, the kind PHP-FPM or Apache's
mod_php serves file by file. `Fixwire\init()` at the top is all it takes.

```sh
composer install    # in examples/
FIXWIRE_DSN=https://<key>@<host> php -d display_errors=0 -S localhost:8080 -t order-page
```

Then:

```sh
curl 'localhost:8080/index.php?id=1001'                          # 200
curl 'localhost:8080/index.php?id=9'                             # 404: not reported
curl -b 'user_id=user-7' 'localhost:8080/index.php?id=1002'      # a bug: reported as a crash, answered 500
```

Order 1002 was saved by an old version of the app, without its items.

What arrives in Fixwire:

- **The crash**: `TypeError: count(): Argument #1 ($value) must be of type
  Countable|array, null given` in `GET /index.php`, with the stack, the
  user `user-7`, the request (`id=1002`) and, as a breadcrumb, the warning
  PHP gave just before (`Undefined array key "items"`). PHP still logs it
  and answers 500, as it would without Fixwire.
- **A trace per request**, with the database lookup under it and the status
  PHP answered with. A caller's `traceparent` header is continued.

How it is wired, in `index.php`:

```php
require dirname(__DIR__) . '/vendor/autoload.php';

Fixwire\init(['release' => 'order-page@1.0.0', 'traces_sample_rate' => 1.0]);

if (isset($_COOKIE['user_id'])) {
    Fixwire\setUser(new Fixwire\User($_COOKIE['user_id']));
}
$order = Fixwire\trace(fn () => $orders[$id] ?? null, 'SELECT orders', 'db.query');
```

In an app with many pages, put the `init()` call in the file every page
includes first (or in `auto_prepend_file`). A fatal error, such as running
out of memory, is reported the same way.
