# CMS AI Plugin

[![Latest Stable](https://img.shields.io/packagist/v/softspring/cms-ai-plugin?label=stable&style=flat-square)](https://github.com/softspring/cms-ai-plugin/releases)
[![Latest Unstable](https://img.shields.io/packagist/v/softspring/cms-ai-plugin?label=unstable&style=flat-square&include_prereleases)](https://github.com/softspring/cms-ai-plugin/releases)
[![License](https://img.shields.io/packagist/l/softspring/cms-ai-plugin?style=flat-square)](https://github.com/softspring/cms-ai-plugin/blob/6.0/LICENSE)
[![PHP Version](https://img.shields.io/packagist/dependency-v/softspring/cms-ai-plugin/php?style=flat-square)](https://github.com/softspring/cms-ai-plugin/blob/6.0/composer.json)
[![Downloads](https://img.shields.io/packagist/dt/softspring/cms-ai-plugin?style=flat-square)](https://packagist.org/packages/softspring/cms-ai-plugin)
[![CI](https://img.shields.io/github/actions/workflow/status/softspring/cms-ai-plugin/ci.yml?branch=6.0&style=flat-square&label=CI)](https://github.com/softspring/cms-ai-plugin/actions/workflows/ci.yml)
[![Coverage](https://img.shields.io/codecov/c/github/softspring/cms-ai-plugin?branch=6.0&style=flat-square)](https://app.codecov.io/gh/softspring/cms-ai-plugin/tree/6.0)

`softspring/cms-ai-plugin` adds AI-assisted admin workflows to Armonic CMS.

This plugin is still in active development. The UI, prompts, generated payload format, and integration points may change before the first stable release.

## What It Provides

- An admin Armonic AI chatbot screen.
- A content editor agent panel inside the CMS version edit screen.
- Media image generation and image description helpers for Media Bundle admin workflows.
- Site-level AI instruction settings stored in CMS site metadata.
- Integration with read-only CMS MCP tools provided by `softspring/cms-mcp-plugin`.
- Schema generation for CMS content version forms through `softspring/form-schema`.
- Payload validation by rendering AI-generated draft changes back into Symfony forms.

## Installation

```bash
composer require softspring/cms-ai-plugin:^6.0@dev
```

The plugin requires `softspring/cms-bundle`, `softspring/cms-mcp-plugin`, `softspring/form-schema`, and `symfony/ai-bundle`.

Register the bundle if Symfony Flex does not do it automatically:

```php
// config/bundles.php
return [
    Softspring\CmsAiPlugin\SfsCmsAiPlugin::class => ['all' => true],
];
```

Configure at least one Symfony AI platform in the host application. For OpenAI, install `symfony/ai-open-ai-platform`, configure the `openai` platform, and provide the API key through the `OPENAI_API_KEY` environment variable.

```yaml
# config/packages/ai_open_ai_platform.yaml
ai:
    platform:
        openai:
            api_key: '%env(OPENAI_API_KEY)%'
```

```dotenv
OPENAI_API_KEY=
```

Do not commit real API keys. Use deployment secrets, Symfony secrets, or a local `.env.local` value.

## Content Editor Agent

The plugin replaces the CMS content version edit view with a two-column layout. The left panel contains an AI agent and the right side keeps the normal CMS editor.

The agent receives the current unsaved form payload, the content version schema, the selected site and locale, and the conversation history for the current edit session. It can call the registered read-only CMS MCP tools for published site context, menus, internal links, analytics, and media context.

Agent responses are applied to the open browser form only. The plugin does not persist a new content version from the agent endpoint. A new CMS version is still created only when the editor manually uses the normal Save action.

The conversation history is stored in the Symfony session for the current content, base version, and layout, so it is kept during the edit session between Save actions.

The editor panel includes a collapsible JSON debug view with the returned patch payload, merged payload, tool calls, and raw model response.

## Armonic AI Chatbot

The plugin exposes an admin Armonic AI chatbot route:

```text
/admin/{_locale}/cms-ai/mcp-chatbot
```

The chatbot uses the configured Symfony AI platform and the registered read-only CMS MCP tools. It is intended for inspecting existing published CMS content, site context, internal links, menus, analytics, and media context through MCP before producing an answer.

The chatbot shows processing metadata below each assistant answer. Duration is measured server-side around the full model and tool-call loop. Token usage is shown when the configured Symfony AI platform and model provider report it through result metadata.

## Site AI Settings

The plugin adds an `AI` tab to CMS site administration pages. Editors can store site-specific instructions such as site description, target audience, editorial tone, brand voice, content guidelines, SEO guidance, forbidden topics, and extra instructions.

The values are stored in the CMS site metadata field under:

```text
site.metadata.sfs_cms_ai
```

The content editor agent includes these instructions in the prompt when a selected site provides them.

## Current Scope

This package is an experimental integration plugin. It is not part of the CMS core and should remain optional because it depends on AI platforms, credentials, model behavior, and generated content review workflows.

## Operational Notes

The plugin needs a configured Symfony AI platform and model. Without that configuration, admin screens can be visible but requests cannot be processed.

Generated changes should be reviewed before saving because model output depends on the provider, model, prompt, site context, and current form payload.

AI-assisted admin requests use the `/admin/{_locale}/cms-ai/` path prefix and can take longer than normal CMS requests while waiting for the model provider. Keep PHP-FPM, Cloud Run, CDN, proxy, and web-server timeouts aligned when changing those workflows.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

[Report issues](https://github.com/softspring/cms-ai-plugin/issues) and [send Pull Requests](https://github.com/softspring/cms-ai-plugin/pulls)

## Security

See [SECURITY.md](SECURITY.md).

## License

This package is free and released under the [AGPL-3.0 license](LICENSE).
