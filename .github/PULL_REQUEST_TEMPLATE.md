## What does this PR do?

<!-- Describe the change and why it's needed -->

## Related issue

<!-- Closes #123, if applicable -->

## Checklist

- [ ] Follows the security patterns used elsewhere in the codebase (nonce/capability checks, `$wpdb->prepare()`, sanitization on superglobals, `hash_equals()` for token comparisons)
- [ ] New hooks are registered via a class's `register()` method against `Erdo_Draft_Links_Loader`
- [ ] `readme.txt` changelog updated, if this is a user-facing change
- [ ] Tested manually against a real WordPress install
