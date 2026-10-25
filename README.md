# SimpleVerifier for PHP

Thin client for the [SimpleVerifier API](https://simpleverifier.com/docs/api): verify an email address (syntax, DNS, disposable, role, live mailbox check) or validate a phone number.

## Install

Not on Packagist yet. Add the GitHub repo to your `composer.json`, then require it:

```bash
composer config repositories.simpleverifier vcs https://github.com/Ahmedmkarrar/simpleverifier-php
composer require simpleverifier/simpleverifier:dev-main
```

Or copy `src/` into your project and `require` both files. Needs PHP 8.1+ with ext-curl.

## Use

```php
<?php
require "vendor/autoload.php"; // or require "src/Exceptions.php"; require "src/Client.php";

$sv = new SimpleVerifier\Client(); // reads SIMPLEVERIFIER_KEY

$result = $sv->verifyEmail("jane@company.com");
echo $result["status"], " ", $result["send_advice"] ?? "";

if ($result["status"] === "invalid") {
    // ask the visitor for another address
}
```

Create a key at https://simpleverifier.com/api-keys (keys start with `sv_live_`; keep them on your server). API access comes with Pro, Business and Agency.

## Results

Email `status` is `valid`, `risky` or `invalid`. Only `valid` means the mail server confirmed the mailbox. Anything unconfirmed (catch-all domain, role address, Outlook/Hotmail/iCloud, server that would not answer) is `risky`. Read `send_advice` (`safe`, `care`, `retry`, `do_not_send`) for what to do. Phone results confirm the format and number type only; they never say a number is in service. Full method: https://simpleverifier.com/how-it-works

## Errors and retries

| Status | Meaning | This client |
| --- | --- | --- |
| 400 | email or phone missing | raises immediately |
| 401 | key missing, wrong or revoked | raises immediately |
| 402 | plan has no API access, subscription inactive, or a yearly plan used this month's calls | raises immediately, never retried; `retry_after` is the seconds until the quota resets when present |
| 429 | more than 120 calls a minute on one key | retried (2 times) after waiting `Retry-After` |
| 503 | verification service briefly unavailable | retried (2 times) with backoff |

All error codes: https://simpleverifier.com/docs/errors

## Tests

```
<?php
require "src/Exceptions.php";
require "src/Client.php";

$sv = new SimpleVerifier\Client(); // reads SIMPLEVERIFIER_KEY
$result = $sv->verifyEmail("jane@company.com");
if ($result["status"] === "invalid") { /* ask for another address */ }

try {
    $phone = $sv->verifyPhone("07911 123456", country: "GB");
} catch (SimpleVerifier\PaymentRequiredException $e) {
    // plan has no API access, or the yearly quota is used; $e->retryAfter = seconds to the 1st (UTC)
}
```

The tests start a local mock HTTP server and make no real API calls.

## License

MIT
