- The leak is the closure kept by the shared service: one more closure, and its captured variables, on every request.
- Keep the callback for this request only: register it, run the code that needs it, and remove it in `finally`
  (`removeListener()` with the same closure kept in a variable), when the service has such a method.
- When the data only needs to reach the listener, pass it through the event or a request attribute, and register the
  listener once (an event subscriber).
- A demonstration whose purpose is to keep the closure is a feature: unless this run allows behaviour changes,
  report it as behaviour-changing.

## When behaviour changes are allowed

- Keep the closure for the current request only: store it in a variable, register it, run what needs it, and remove
  it in `finally` when the service can remove it (`removeListener()`).
- When the service cannot remove it, stop handing the closure to the shared service: call it directly, or move the
  captured value into the event payload. The response of the route stays the same shape.
