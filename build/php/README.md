# Custom static PHP runtime

Evitan Plus stores every local record in **SQLite** and, from Phase M1 onward,
reaches its branch database in **Supabase Postgres** directly
(`MULTIBRANCH-BRAINSTORM.md` §5; the `pgsql` connection is already defined in
`config/database.php`). The PHP runtime NativePHP bundles into the desktop app
must therefore carry `pdo_sqlite` **and** `pdo_pgsql`.

NativePHP's own `nativephp/php-bin` zips carry only the SQLite side — see
`vendor/nativephp/php-bin/php-extensions.txt` — so we ship our own and point
NativePHP at it:

```dotenv
# .env — RELATIVE to the project root; NativePHP appends
# "bin/<os>/<arch>/php-<minor>.zip"
NATIVEPHP_PHP_BINARY_PATH=./build/php/
```

The release target is **Linux x64 only** (README, "Building a release"), so
there is exactly one zip:

```
build/php/bin/linux/x64/php-8.5.zip     # the zip contains a single static `php`
```

It is committed to the repo (`.gitignore` ignores `/build/*` but re-includes
`/build/php/`) so a fresh clone can cut a release with no out-of-band download.

## What's in the shipped runtime

Custom static PHP **8.5.7** (musl), built with
[static-php-cli](https://github.com/crazywhalecc/static-php-cli) 2.8.6. Verified
to load `pdo_pgsql`, `pgsql`, `pdo_sqlite`, and `sqlite3`
(`PDO::getAvailableDrivers() = [pgsql, sqlite]`) and to round-trip a row against
both SQLite and live Supabase Postgres from inside the NativePHP shell.

Extensions are NativePHP's stock set **plus `pdo_pgsql, pgsql`**, minus `bz2`
(no code path in the app compresses data). The
runtime additionally carries `calendar, dba, exif, gmp, pcntl, posix, readline,
xsl`, which are harmless extras from the upstream build profile.

`tests/Feature/Build/PhpBinaryTest.php` asserts all of the above against the
real zip on every test run, so a driverless or drifted runtime cannot reach a
release build.

## Version pins (keep in sync)

| What | Pin | Where |
|---|---|---|
| PHP minor | **8.5** | zip filename (`php-8.5.zip`) |
| NativePHP desktop | `^1.3` | `composer.json` |

## Gotchas

- **Host-PHP-minor coupling:** NativePHP requests the zip matching the *host*
  PHP minor that launches `native:dev` / `native:build`
  (`BuildCommand::getEnvironmentVariables()` passes
  `PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION`). Keep your dev machine's PHP and
  the shipped zip on the same minor, or the app silently builds against a
  missing file. `PhpBinaryTest` fails loudly if they diverge.
- **Never set `NATIVEPHP_PHP_BINARY_PATH=./`.** `native:build` prunes
  `<build copy>/<binary path>`; with `./` it tries to delete the entire copied
  app and the build dies with `Failed to remove directory ".../resources/app/./"`.
- **The zip is not shipped inside the app.** `build` is already in
  `nativephp.cleanup_exclude_files`, and the binary is read from the project
  root (`sourcePath()`), never from the build copy — so excluding it is safe and
  keeps 24MB of dead weight out of the installer.
- **NativePHP upgrades can change the required extension list.** After an
  upgrade, `PhpBinaryTest` diffs this runtime against
  `vendor/nativephp/php-bin/php-extensions.txt` for you; a failure there means
  the runtime needs rebuilding.

## Rebuilding

```bash
# in a static-php-cli checkout
./bin/spc download --for-extensions="<list>" --with-php=8.5
./bin/spc build "bcmath,bz2,ctype,curl,dom,fileinfo,filter,gd,iconv,intl,mbstring,\
mbregex,opcache,openssl,pdo,pdo_pgsql,pdo_sqlite,pgsql,phar,session,simplexml,\
sockets,sodium,sqlite3,tokenizer,xml,zip,zlib" \
  --with-libs=libjpeg,freetype,libwebp --build-cli
zip -j php-8.5.zip buildroot/bin/php
```

Then replace `bin/linux/x64/php-8.5.zip` and run
`php artisan test --filter=PhpBinaryTest`.
