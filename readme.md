# MET.no forecast library

Small library for getting forecast for given coordinates. Updated to newest API, originally made in 2012.

Endpoint: https://api.met.no/weatherapi/locationforecast/2.0/classic

## Usage

Basic usage you can find in example.php

You can install this library via composer or download the classes and to load all the classes you can use loader_METno.php

Add to composer require (requires addid this GIT repository as VCS repo in your composer.json)

	"hikingsk/metno": ">=1.0.0"

## Caching and MET.no Terms of Service

Since 1.0.17 the library follows the [MET.no Terms of Service](https://docs.api.met.no/doc/TermsOfService.html)
and the [Locationforecast FAQ](https://docs.api.met.no/doc/locationforecast/FAQ) (Nov 2026 revision):

- **Coordinates** are truncated to 4 decimals (more decimals → `403`, and they defeat caching).
- **Minimum caching time**: 30 min in the Nordic region, 3 h for the rest of the world — or longer
  when the `Expires` header says so — plus a random delay (`setCacheJitter()`, default ≤ 10 min) so
  refetches never bunch up at XX:00. `setMinCacheTimes()` can only raise the minimums.
- **Revalidation**: an expired entry is refetched with `If-Modified-Since` (the previous
  `Last-Modified`); a `304` only extends it.
- **Failures** (network error, a status other than 200/203, truncated XML, `429`) are not retried for
  `setErrorBackoff()` seconds (default 10 min, a longer `Retry-After` wins). Meanwhile the last good
  forecast is served if it is not older than `setMaxStale()` (default 24 h); `$forecast->isStale()`
  tells you so.
- **gzip** responses are accepted.
- **One refresh per location at a time** (`flock` on `<cache file>.lock`): parallel workers serve the expired copy
  meanwhile instead of repeating the request. A cache write failure is reported via `error_log` — without a cache
  every call would go to MET.no.
- Set a **User-Agent** with your app/domain and a contact e-mail: `METnoFactory::setUserAgent()`.

One cache file per location (`metno-lat…_lon…[_altitude…].cache` in the cache dir — JSON metadata
line + raw XML), written atomically. Unused files, temp files and the per-day folders of older
versions are cleaned up automatically, no cron needed.

Tests (no network, PHP ≥ 5.3): `php tests/run.php`

## METnoDay

This class represents the forecast for the day. It has several properties you can access. By default the symbol and values is taken from the highest temperature, you can also set the hour which should be used.

The day has also forecast for every day (represented by METnoForecast class). When API has missing hour, the parsing will ensure that the hour will be filled by previous/next value.

Also you can access night forecast, hour forecast for highest or lowest temperature.

## Custom settings

All custom settings can be made in METnoFactory via setters. You can change the class of the symbol (representing the forecast icon) and percipitation (representing values)

## TODO

Possible features that could be made

### METno
- info abaout location, should be added to METnoDay too
- time by location
- detection of night by sunset (currently detection is made by hour)

### METnoDay
- mornig forecast
- correct hour symbol from the form


