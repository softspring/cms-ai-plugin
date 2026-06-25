# CMS AI Plugin Features

Functional definition for `softspring/cms-ai-plugin`.

This plugin is in active development. It is useful for experimentation and early Armonic AI workflows, but the public behavior is not stable yet.

## Purpose

`cms-ai-plugin` provides optional AI-assisted CMS admin tooling for Armonic. It helps editors inspect CMS context, generate draft form patches from CMS form schemas, validate those patches, and review them before saving.

## Main Features

- Register as an Armonic CMS plugin with alias `sfs_cms_ai`.
- Provide an admin Armonic AI chatbot screen for CMS MCP-assisted questions.
- Extend the CMS content version edit screen with an AI agent panel.
- Show server-side processing duration and provider token usage metadata in the MCP chatbot when available.
- Provide a site-level AI settings tab in CMS site administration.
- Store site-level AI instructions in `site.metadata.sfs_cms_ai`.
- Consume read-only CMS MCP tools provided by `softspring/cms-mcp-plugin`.
- List configured CMS content types and compatible layouts.
- Discover configured Symfony AI platforms and model catalogs.
- Generate schemas for CMS content version forms.
- Prompt AI platforms to return draft form patches for the current unsaved CMS version edit session.
- Keep the normal CMS Save action as the only action that creates a new content version from editor changes.
- Include configured site-level AI instructions in content editor prompts.
- Decode and normalize model responses.
- Validate generated draft patches with Symfony forms after merging them with the current browser payload.
- Apply content editor agent responses to the browser form without saving a new CMS version.
- Keep content editor agent conversation history in the Symfony session for the current content, base version, and layout.
- Show returned patch payload, merged payload, tool calls, raw model response, and validation errors in the editor debug panel.
- Provide media image generation and image description helpers for Media Bundle admin workflows.

## Dependencies

- `softspring/cms-bundle` for CMS configuration, managers, content entities, and admin form types.
- `softspring/form-schema` for form-to-schema extraction.
- `symfony/ai-bundle` for AI platform integration.
- `softspring/cms-mcp-plugin` for CMS MCP tool implementations and MCP server integration.

## Extension Points

- Add support for more form fields in `form-schema` when generated schemas are incomplete.
- Add additional AI workflows around the content editor and media admin integrations.
- Keep MCP tooling in `softspring/cms-mcp-plugin` so this package can focus on admin AI experiences.

## Current Limits

- The content editor agent is experimental and depends on the browser form structure.
- Generated content must be reviewed before use.
- Model behavior is provider-dependent.
- MCP tools are read-only and expose published CMS data only.
- The plugin does not configure AI platforms by itself; the host application must provide them.
- AI-assisted admin requests can take longer than normal CMS requests and need aligned runtime timeouts in the host application.
