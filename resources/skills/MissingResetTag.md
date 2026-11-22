- Implement `Symfony\Contracts\Service\ResetInterface` (with its `use` line): autoconfiguration adds the
  `kernel.reset` tag. Never edit YAML.
- Write a `reset()` that assigns every property `clear()` clears, directly. A `reset()` that only calls `clear()` is
  reported as IncompleteReset, because Igor does not follow the call.
- Keep `clear()` as it is: other code may call it.
