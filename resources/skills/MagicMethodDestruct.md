- Move the per-request cleanup to `reset()` (`Symfony\Contracts\Service\ResetInterface`), or to a `finally` in the
  method that opens the resource, and remove `__destruct()`: Igor reports the method as long as it exists.
- Removing `__destruct()` changes when the code runs (worker stop instead of object destruction): if the timing is the
  point of the code and this run does not allow behaviour changes, report it as behaviour-changing.

## When behaviour changes are allowed

- Rename the cleanup into `reset()`, implement `Symfony\Contracts\Service\ResetInterface` (merge into an existing
  `reset()` if there is one) and remove `__destruct()`. The cleanup then runs after each request instead of at
  worker stop.
