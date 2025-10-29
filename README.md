![Examine.com](./doc/examine-logo.png)
# 📚 PHP SIP2 Plus


---

## Overview

**PHP SIP2 Plus** is a maintained and improved implementation of the **SIP2 protocol** used by Integrated Library Systems (ILS) for communication with self-check machines, circulation systems, and other library services.

This library builds on the original [`php-sip2`](https://github.com/cap60552/php-sip2) project by [John Wohlers](https://github.com/cap60552), extending it with:

- ✅ **TLS support** — works with both raw sockets and TLS/SSL
- ✅ **Extensible transport layer** — easily integrate new connection types
- ✅ **Clean, object-oriented API** for sending and parsing SIP2 messages
- ✅ **Composer support** and PSR-compliant structure
- ✅ **Actively maintained** by [Examine.com](https://examine.com)

---

## Why this library?

Many existing SIP2 PHP clients are outdated, limited to plain socket connections, or hard to extend.  
This version offers a **modern architecture** that makes it easy to adapt to different network environments, integrate with secure servers, and extend protocol behavior as needed.

---

## Installation

You can install the package via Composer:

```bash
composer require examinecom/php-sip2-plus
```


## Example
```php
$sip2 = new Sip2Wrapper(
    array(
        'hostname' => 'sip-demo.evergreen-ils.org',
        'port' => 6443,
        'location' => 'CONS',
        'institutionId' => 'sample',
        'useTls' => true,
    )
);
$sip2->connect();
$sip2->login('admin', 'demo123');
$sip2->startPatronSession('88882000000028', 'demo123');
```


## Credits
- Original implementation by [John Wohlers](https://github.com/cap60552)
- `Sip2Wrapper` by [Nathan Johnson](https://nathanjohnson.info/)