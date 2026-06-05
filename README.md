# CMS AI Plugin

[![Latest Stable](https://img.shields.io/packagist/v/softspring/cms-ai-plugin?label=stable&style=flat-square)](https://github.com/softspring/cms-ai-plugin/releases)
[![Latest Unstable](https://img.shields.io/packagist/v/softspring/cms-ai-plugin?label=unstable&style=flat-square&include_prereleases)](https://github.com/softspring/cms-ai-plugin/releases)
[![License](https://img.shields.io/packagist/l/softspring/cms-ai-plugin?style=flat-square)](https://github.com/softspring/cms-ai-plugin/blob/6.0/LICENSE)
[![PHP Version](https://img.shields.io/packagist/dependency-v/softspring/cms-ai-plugin/php?style=flat-square)](https://github.com/softspring/cms-ai-plugin/blob/6.0/composer.json)
[![Downloads](https://img.shields.io/packagist/dt/softspring/cms-ai-plugin?style=flat-square)](https://packagist.org/packages/softspring/cms-ai-plugin)
[![CI](https://img.shields.io/github/actions/workflow/status/softspring/cms-ai-plugin/ci.yml?branch=6.0&style=flat-square&label=CI)](https://github.com/softspring/cms-ai-plugin/actions/workflows/ci.yml)
[![Coverage](https://img.shields.io/codecov/c/github/softspring/cms-ai-plugin?branch=6.0&style=flat-square)](https://codecov.io/gh/softspring/cms-ai-plugin)

`softspring/cms-ai-plugin` adds experimental AI-assisted content generation tools to Armonic CMS.

This plugin is still in active development. The UI, prompts, generated payload format, persistence flow, and integration points may change before the first stable release.

## What It Provides

- An admin content lab to generate test CMS content payloads with Symfony AI.
- Schema generation for CMS content version forms through `softspring/form-schema`.
- Payload validation by rendering generated data back into Symfony forms.
- Optional persistence of valid generated payloads as new CMS content with an initial version.

## Installation

```bash
composer require softspring/cms-ai-plugin:^6.0@dev
```

The plugin requires `softspring/cms-bundle`, `softspring/form-schema`, and `symfony/ai-bundle`.

Register the bundle if Symfony Flex does not do it automatically:

```php
// config/bundles.php
return [
    Softspring\CmsAiPlugin\SfsCmsAiPlugin::class => ['all' => true],
];
```

Configure at least one Symfony AI platform in the host application. For example, an OpenAI platform can be configured in the application using Symfony AI configuration and environment variables.

## Admin Lab

The plugin exposes an admin lab route:

```text
/admin/{_locale}/cms-ai/content-lab
```

The lab lets an administrator choose a content type, layout, AI platform, and model. It then builds a schema from the CMS form, asks the model for a JSON payload, validates the payload, and can persist it as CMS content when valid.

## Current Scope

This package is an experimental integration plugin. It is not part of the CMS core and should remain optional because it depends on AI platforms, credentials, model behavior, and generated content review workflows.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

[Report issues](https://github.com/softspring/cms-ai-plugin/issues) and [send Pull Requests](https://github.com/softspring/cms-ai-plugin/pulls)

## Security

See [SECURITY.md](SECURITY.md).

## License

This package is free and released under the [AGPL-3.0 license](LICENSE).
