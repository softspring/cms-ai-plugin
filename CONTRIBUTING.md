# Contributing

This plugin is in active development. Keep changes focused and document behavior that affects generated content, validation, or persistence.

Use the standard package commands before sending changes:

```bash
composer fix
composer test
composer test-bc
```

Keep the existing `EventSubscriberInterface` contract when maintaining event subscribers.
Their public `getSubscribedEvents()` method is used by service tags and application integrations.
Rector excludes `EventSubscriberToAttributeRector` to preserve this contract; other Symfony rules remain enabled.

[Report issues](https://github.com/softspring/cms-ai-plugin/issues) and [send Pull Requests](https://github.com/softspring/cms-ai-plugin/pulls)
