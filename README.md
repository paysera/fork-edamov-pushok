# Paysea fork Edamov Pushok

Original documentation can be found: https://github.com/edamov/pushok

## Major changes:

* `Client` is stateless - no mutating `notifications` array.
* 2nd argument to `Client` constructor must be `LoggerInterface`.
* added `pushOne` method to `Client` - uses pure `curl` without any `curl_multi_*`.
