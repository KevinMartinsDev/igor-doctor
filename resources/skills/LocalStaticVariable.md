- Move the value to a private property of the class, implement `Symfony\Contracts\Service\ResetInterface` and assign
  the property its initial value in `reset()`.
- If the static is only a memoization within one call, a plain local variable is enough.
