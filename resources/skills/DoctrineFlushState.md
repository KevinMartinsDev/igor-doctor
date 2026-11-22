- Clear the collected property at the start of the collecting event (`onFlush` or `preFlush`) with a direct
  assignment, so a failed commit cannot leave it set for the next request.
